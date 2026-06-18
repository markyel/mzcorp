<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Язык общения с поставщиком (Фаза 3.2/3.3). Письмо-запрос и список
 * номенклатуры формируются на этом языке; для каталожных позиций (M-артикул)
 * берётся английское название (catalog_items.name_en). Часть поставщиков —
 * иностранные (eastelevator.cn, oss-elevator-parts.com и т.п.), переписка на EN.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('suppliers', 'language')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->string('language', 8)->default('ru'); // ru | en
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'language')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }
    }
};
