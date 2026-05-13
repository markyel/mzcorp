<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.10 — state-machine fields для request'ов (Foundation §5).
 *
 * Pause-mehanic:
 *  - paused_until: дата автоматического resume через requests:resume-paused.
 *  - paused_from_status: куда возвращать после resume.
 *  - paused_reason: текст оператора («отпуск поставщика», «каникулы клиента»).
 *
 * Terminal-state:
 *  - closed_at: когда заявка стала terminal (для аналитики).
 *  - closed_lost_reason: enum-значение из ClosedLostReason.
 *  - closed_lost_comment: свободный текст (обязателен для *_other reason'ов).
 *
 * Колонка status varchar(32) → varchar(40): новые значения вроде
 * `awaiting_client_clarification` (28 символов) и `postponed_until` влезают,
 * но запас под будущее.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'paused_until')) {
                $table->timestamp('paused_until')->nullable()->after('assigned_at');
            }
            if (! Schema::hasColumn('requests', 'paused_from_status')) {
                $table->string('paused_from_status', 40)->nullable()->after('paused_until');
            }
            if (! Schema::hasColumn('requests', 'paused_reason')) {
                $table->text('paused_reason')->nullable()->after('paused_from_status');
            }
            if (! Schema::hasColumn('requests', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('paused_reason');
            }
            if (! Schema::hasColumn('requests', 'closed_lost_reason')) {
                $table->string('closed_lost_reason', 64)->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('requests', 'closed_lost_comment')) {
                $table->text('closed_lost_comment')->nullable()->after('closed_lost_reason');
            }
        });

        // Расширяем status до varchar(40). На Postgres ALTER COLUMN TYPE
        // безопасно для уже существующих значений (3-32 chars влезут).
        DB::statement('ALTER TABLE requests ALTER COLUMN status TYPE VARCHAR(40)');

        // Индекс для дашбордного запроса «активные за период / просроченные».
        // CONCURRENTLY нельзя внутри транзакции миграции; обычный CREATE INDEX
        // на ~200 строк отработает за миллисекунды.
        $hasIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_status_closed_at_index'"
        ))->isNotEmpty();
        if (! $hasIdx) {
            DB::statement('CREATE INDEX requests_status_closed_at_index ON requests (status, closed_at)');
        }
    }

    public function down(): void
    {
        $hasIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_status_closed_at_index'"
        ))->isNotEmpty();
        if ($hasIdx) {
            DB::statement('DROP INDEX requests_status_closed_at_index');
        }

        Schema::table('requests', function (Blueprint $table) {
            foreach (['paused_until', 'paused_from_status', 'paused_reason',
                      'closed_at', 'closed_lost_reason', 'closed_lost_comment'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // status: возврат varchar(32) безопасен если все текущие значения <= 32.
        // На случай если кто-то уже сохранил длинное значение — обрезаем (best-effort).
        DB::statement('UPDATE requests SET status = LEFT(status, 32) WHERE LENGTH(status) > 32');
        DB::statement('ALTER TABLE requests ALTER COLUMN status TYPE VARCHAR(32)');
    }
};
