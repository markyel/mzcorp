<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доставка в КП: текст + срок + цена. Цена прибавляется к итогу, скидки на неё
 * НЕ распространяются (см. QuotationService::recalcTotals). НДС в т.ч.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'delivery_text')) {
                $table->text('delivery_text')->nullable()->after('client_comment');
            }
            if (! Schema::hasColumn('quotations', 'delivery_term')) {
                $table->string('delivery_term', 255)->nullable()->after('delivery_text');
            }
            if (! Schema::hasColumn('quotations', 'delivery_price')) {
                $table->decimal('delivery_price', 14, 2)->default(0)->after('delivery_term');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            foreach (['delivery_price', 'delivery_term', 'delivery_text'] as $col) {
                if (Schema::hasColumn('quotations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
