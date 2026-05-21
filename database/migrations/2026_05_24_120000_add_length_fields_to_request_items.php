<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мерные позиции: вторая размерность qty × length.
 *
 * До этой миграции вторая размерность (например, «43.56 м каждый» для
 * поручня) приходила из парсера в `supplier_note` как свободный текст.
 * Расчёт total = price × qty игнорировал длину, что приводило к занижению
 * суммы в десятки раз для мерных товаров (поручни, канаты, цепи, кабели).
 *
 * Теперь длина хранится структурированно:
 *   parsed_length      — число (43.560)
 *   parsed_length_unit — единица измерения второй размерности («м», «п.м.», «кг»)
 *   billing_unit       — override от менеджера, по какой единице считать total:
 *                          null      → как раньше, total = price × parsed_qty;
 *                          == parsed_unit       → то же самое;
 *                          == parsed_length_unit → total = price × parsed_qty × parsed_length.
 *
 * supplier_note остаётся для произвольных пометок («требуется фото шильдика»).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'parsed_length')) {
                $table->decimal('parsed_length', 12, 3)->nullable()->after('parsed_unit');
            }
            if (! Schema::hasColumn('request_items', 'parsed_length_unit')) {
                $table->string('parsed_length_unit', 16)->nullable()->after('parsed_length');
            }
            if (! Schema::hasColumn('request_items', 'billing_unit')) {
                $table->string('billing_unit', 16)->nullable()->after('parsed_length_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            foreach (['billing_unit', 'parsed_length_unit', 'parsed_length'] as $col) {
                if (Schema::hasColumn('request_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
