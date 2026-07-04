<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Частичная оплата счёта (импорт оплат из 1С, Оп% < 100):
 *  - partially_paid_at — момент частичной оплаты (paid_at при этом НЕ ставим,
 *    чтобы отчёты по paid_at не считали частичные полными);
 *  - paid_amount — фактически поступившая сумма из выгрузки 1С (и для полной,
 *    и для частичной оплаты; может отличаться от amount_snapshot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'partially_paid_at')) {
                $table->timestamp('partially_paid_at')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 14, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'partially_paid_at')) {
                $table->dropColumn('partially_paid_at');
            }
            if (Schema::hasColumn('invoices', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });
    }
};
