<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `quotation_items.catalog_lead_time_days` — снимок `catalog_items.lead_time_days`
 * на момент добавления позиции в КП (QuotationService, Quotations\Editor).
 * Был smallint (unsignedSmallInteger на Postgres = smallint, до 32767).
 *
 * После 2026_11_01_110000 каталог хранит integer, и в нём уже лежит
 * 100034 (M02619, служебный код 1С в «СрокПоставки»). Без этой миграции
 * добавление такой позиции в КП падало бы с `SQLSTATE[22003]: value
 * "100034" is out of range for type smallint`.
 *
 * Расширяем до integer — в паре с catalog_items.lead_time_days.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('quotation_items', 'catalog_lead_time_days')) {
            return;
        }
        DB::statement('ALTER TABLE quotation_items ALTER COLUMN catalog_lead_time_days TYPE INTEGER');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quotation_items', 'catalog_lead_time_days')) {
            return;
        }
        // Сужаем обратно, только если все значения влезают в smallint —
        // иначе ALTER упадёт, а резать данные откатом нельзя.
        $max = DB::table('quotation_items')->max('catalog_lead_time_days');
        if ($max !== null && (int) $max > 32767) {
            return;
        }
        DB::statement('ALTER TABLE quotation_items ALTER COLUMN catalog_lead_time_days TYPE SMALLINT');
    }
};
