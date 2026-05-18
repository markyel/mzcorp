<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — добавляем «розничную» (до скидки) цену и %-скидки к outbound_quote_items.
 *
 * Контекст: в типовом шаблоне Liftway/MyZip-PDF колонки идут так:
 *    «Цена» (база) | «% Скидка» | «Цена со скидкой» | «Сумма» | «НДС в т.ч.»
 *
 * До этой миграции мы хранили только финальную `unit_price` = «Цена со скидкой».
 * Это удобно для расчёта суммы, но скрывает партнёрскую наценку: глядя на КП
 * в UI РОП не видит «вот тут было 523.24, мы отдали за 286.53» и не может
 * проверить корректность скидки.
 *
 * Новые колонки:
 *  - base_unit_price (14, 4) — цена из колонки «Цена» (без скидки), за 1 единицу.
 *    null если в документе нет колонки «Цена» или КП без скидок.
 *  - discount_percent (5, 2) — процент скидки из колонки «% Скидка». null если
 *    колонки нет. Хранится как явное значение из документа, а не вычисляется
 *    `1 - unit_price/base_unit_price` (документ — источник истины).
 *
 * Invariants (валидирует парсер, не БД, чтобы старые ряды не падали):
 *  - если оба заполнены: base_unit_price * (1 - discount_percent/100) ≈ unit_price (±1%).
 *  - если base_unit_price = unit_price → discount_percent должен быть 0 или null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbound_quote_items')) {
            return;
        }

        Schema::table('outbound_quote_items', function (Blueprint $table) {
            if (! Schema::hasColumn('outbound_quote_items', 'base_unit_price')) {
                $table->decimal('base_unit_price', 14, 4)->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('outbound_quote_items', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('base_unit_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbound_quote_items')) {
            return;
        }

        Schema::table('outbound_quote_items', function (Blueprint $table) {
            if (Schema::hasColumn('outbound_quote_items', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
            if (Schema::hasColumn('outbound_quote_items', 'base_unit_price')) {
                $table->dropColumn('base_unit_price');
            }
        });
    }
};
