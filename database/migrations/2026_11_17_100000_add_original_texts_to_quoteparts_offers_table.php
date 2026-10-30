<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Поставщик ответил по-китайски: в note/brand/part_number/product_name — русский перевод
        // (ForeignTextTranslator), здесь — что он написал на самом деле, по полям.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'original_texts')) {
                $table->jsonb('original_texts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'original_texts')) {
                $table->dropColumn('original_texts');
            }
        });
    }
};
