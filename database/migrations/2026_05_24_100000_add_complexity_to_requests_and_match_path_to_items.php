<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complexity-механизм заявок (см. App\Enums\MatchPath / ComplexityLevel
 * / App\Services\Request\RequestComplexityService).
 *
 *   - `request_items.match_path` — snapshot входной сложности позиции:
 *     internal_sku | brand_article | name_match | manual.
 *     Выводится из `quality_assessment_payload.catalog_match.method`
 *     при создании / резолве; после ручной правки менеджером НЕ меняется.
 *
 *   - `requests.complexity_score` — int, Σ MatchPath::defaultWeight()
 *     по всем active items заявки. Конфигурируется через AppSetting
 *     (`complexity.weights.*`).
 *
 *   - `requests.complexity_level` — enum, выводится из score по порогам
 *     (AppSetting `complexity.thresholds.*`). Денормализовано для быстрых
 *     bucket-фильтров и Dashboard breakdown.
 *
 * Backfill match_path делается в CLI `requests:recompute-complexity --backfill`
 * чтобы не блокировать миграцию (3000+ items с jsonb-чтением).
 *
 * Indexes:
 *   - request_items.match_path — для Dashboard breakdown (COUNT GROUP BY)
 *   - requests.complexity_level — для bucket-фильтра в Pool
 *   - requests.complexity_score DESC — для сортировки в Pool по сложности
 */
return new class extends Migration {
    public function up(): void
    {
        // ─── request_items.match_path ──────────────────────────────
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'match_path')) {
                $table->string('match_path', 32)->nullable()->after('catalog_item_id');
            }
        });

        $constraintName = 'request_items_match_path_check';
        $hasConstraint = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conname = ?",
            [$constraintName],
        ))->isNotEmpty();
        if (! $hasConstraint) {
            DB::statement(
                "ALTER TABLE request_items ADD CONSTRAINT {$constraintName} "
                . "CHECK (match_path IS NULL OR match_path IN "
                . "('internal_sku', 'brand_article', 'name_match', 'manual'))"
            );
        }

        $hasIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'request_items' AND indexname = 'request_items_match_path_idx'"
        ))->isNotEmpty();
        if (! $hasIdx) {
            DB::statement(
                'CREATE INDEX request_items_match_path_idx ON request_items (match_path) '
                . 'WHERE match_path IS NOT NULL'
            );
        }

        // ─── requests.complexity_score + complexity_level ──────────
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'complexity_score')) {
                $table->integer('complexity_score')->default(0)->after('last_activity_type');
            }
            if (! Schema::hasColumn('requests', 'complexity_level')) {
                $table->string('complexity_level', 16)->default('easy')->after('complexity_score');
            }
        });

        $levelConstraint = 'requests_complexity_level_check';
        $hasLevelConstraint = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE conname = ?",
            [$levelConstraint],
        ))->isNotEmpty();
        if (! $hasLevelConstraint) {
            DB::statement(
                "ALTER TABLE requests ADD CONSTRAINT {$levelConstraint} "
                . "CHECK (complexity_level IN ('easy', 'normal', 'hard', 'very_hard'))"
            );
        }

        $hasComplexityIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_complexity_idx'"
        ))->isNotEmpty();
        if (! $hasComplexityIdx) {
            // Composite: assigned_user + level + score DESC — для Pool
            // bucket-фильтра «Сложные» с сортировкой по тяжести.
            DB::statement(
                'CREATE INDEX requests_complexity_idx '
                . 'ON requests (assigned_user_id, complexity_level, complexity_score DESC)'
            );
        }

        // ─── AppSetting defaults (5 ключей: 4 веса + thresholds JSON) ──
        // Insert if not exists. updated_by_user_id NULL = system seed.
        $now = now()->toDateTimeString();
        $defaults = [
            ['complexity.weights.internal_sku', '1', 'int', 'Сложность: вес позиции с M-артикулом (auto-resolved)'],
            ['complexity.weights.brand_article', '2', 'int', 'Сложность: вес позиции с brand_article (OEM-кодом)'],
            ['complexity.weights.name_match', '3', 'int', 'Сложность: вес позиции, сматченной по названию (vector + LLM)'],
            ['complexity.weights.manual', '8', 'int', 'Сложность: вес позиции, требующей ручного матчинга'],
            ['complexity.thresholds', '{"easy_max":5,"normal_max":18,"hard_max":45}', 'json', 'Сложность: пороги уровней. score > hard_max → very_hard'],
        ];
        foreach ($defaults as [$key, $value, $type, $desc]) {
            $exists = DB::table('app_settings')->where('key', $key)->exists();
            if (! $exists) {
                DB::table('app_settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'type' => $type,
                    'description' => $desc,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // AppSetting keys — удаляем
        DB::table('app_settings')->whereIn('key', [
            'complexity.weights.internal_sku',
            'complexity.weights.brand_article',
            'complexity.weights.name_match',
            'complexity.weights.manual',
            'complexity.thresholds',
        ])->delete();

        // Indexes
        foreach ([
            'requests_complexity_idx',
            'request_items_match_path_idx',
        ] as $idx) {
            $exists = collect(DB::select(
                "SELECT indexname FROM pg_indexes WHERE indexname = ?",
                [$idx],
            ))->isNotEmpty();
            if ($exists) {
                DB::statement("DROP INDEX {$idx}");
            }
        }

        // Constraints
        foreach ([
            'requests_complexity_level_check',
            'request_items_match_path_check',
        ] as $constraint) {
            $exists = collect(DB::select(
                "SELECT conname FROM pg_constraint WHERE conname = ?",
                [$constraint],
            ))->isNotEmpty();
            if ($exists) {
                DB::statement(
                    str_starts_with($constraint, 'requests_')
                        ? "ALTER TABLE requests DROP CONSTRAINT {$constraint}"
                        : "ALTER TABLE request_items DROP CONSTRAINT {$constraint}"
                );
            }
        }

        // Columns
        Schema::table('requests', function (Blueprint $table) {
            foreach (['complexity_score', 'complexity_level'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'match_path')) {
                $table->dropColumn('match_path');
            }
        });
    }
};
