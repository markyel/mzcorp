<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новые поля из обновлённого экспорта 1С/MDB:
 *
 *   - «ЦенаЗакупки» → purchase_price — закупочная цена (себестоимость).
 *     ВНУТРЕННЕЕ поле: показывается только менеджеру/РОПу/директору,
 *     НИКОГДА не транслируется клиенту (в отличие от price/price_min).
 *   - «СвободноВПути» → stock_in_transit — «свободный остаток в пути»:
 *     разобранный список [{qty:int, date:'Y-m-d'}] (кол-во + дата прихода).
 *     jsonb, т.к. структура переменной длины (обычно 0-3 партии).
 *
 * После применения первый импорт пометит все строки как rows_updated
 * (source_hash расширен новыми полями) — это ожидаемо и безвредно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_items', 'purchase_price')) {
                $table->decimal('purchase_price', 12, 2)->nullable()->after('price_min');
            }
            if (! Schema::hasColumn('catalog_items', 'stock_in_transit')) {
                $table->jsonb('stock_in_transit')->nullable()->after('lead_time_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            foreach (['purchase_price', 'stock_in_transit'] as $col) {
                if (Schema::hasColumn('catalog_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
