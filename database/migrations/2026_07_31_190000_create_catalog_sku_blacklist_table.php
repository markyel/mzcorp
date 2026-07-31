<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чёрный список M-артикулов каталога (дубли из MDB и т.п.).
 *
 * Позиции с этими SKU исключаются из каталога (is_active=false) и НЕ
 * возвращаются при повторных импортах: CatalogImportService выкидывает их
 * из снапшота ДО обработки, поэтому is_active никогда не переставляется в true.
 * Список переживает импорты, т.к. живёт в отдельной таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_sku_blacklist')) {
            return;
        }
        Schema::create('catalog_sku_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sku_blacklist');
    }
};
