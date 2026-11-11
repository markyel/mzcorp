<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наименование предлагаемого товара от поставщика (11.11.2026): QuoteParts отдаёт его в предложении
 * (offers.description) для любого вида — оригинал, аналог, копия, восстановленный, замена. Китайский
 * текст переводится, как бренд и примечание (оригинал — в original_texts).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            return;
        }
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'description')) {
                $table->string('description', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            return;
        }
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
