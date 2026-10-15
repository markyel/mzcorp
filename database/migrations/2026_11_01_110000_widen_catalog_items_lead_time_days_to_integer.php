<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `catalog_items.lead_time_days` был smallint (до 32767) — миграция
 * 2026_05_15_130000 считала, что «СрокПоставки» из 1С — это дни. На деле
 * в поле лежат и служебные коды: 999, 1000–1035, 10001, 10034, а с
 * 2026-10-08 у M02619 пришло 100034.
 *
 * Симптом 2026-10-08 23:00 → 2026-10-15: каждый прогон `catalog:import`
 * падал с `SQLSTATE[22003]: value "100034" is out of range for type
 * smallint`, транзакция откатывалась целиком — цены и остатки каталога
 * неделю не обновлялись.
 *
 * Расширяем до integer. smallint → integer на Postgres — безопасное
 * расширение, данные не теряются.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_items', 'lead_time_days')) {
            return;
        }
        DB::statement('ALTER TABLE catalog_items ALTER COLUMN lead_time_days TYPE INTEGER');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('catalog_items', 'lead_time_days')) {
            return;
        }
        // Сужаем обратно, только если все значения влезают в smallint —
        // иначе ALTER упадёт, а резать данные откатом нельзя.
        $max = DB::table('catalog_items')->max('lead_time_days');
        if ($max !== null && (int) $max > 32767) {
            return;
        }
        DB::statement('ALTER TABLE catalog_items ALTER COLUMN lead_time_days TYPE SMALLINT');
    }
};
