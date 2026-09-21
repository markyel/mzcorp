<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заголовки объявлений по складским позициям.
 *
 * Базовый заголовок всегда собирается правилами (DirectAdPlanService), но
 * каталожные имена писались для склада, а не для рекламы, и в 56 символов
 * влезают обрубками. Там, где правилам плохо, заголовок пишет модель — и
 * результат СОХРАНЯЕТСЯ здесь: каждое изменение текста объявления отправляет
 * его на повторную модерацию, поэтому заголовок должен быть стабильным, а не
 * пересобираться при каждом прогоне.
 *
 * source: rule | ai | manual — видно, откуда взялся текст.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_ad_titles')) {
            return;
        }

        Schema::create('direct_ad_titles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
            $t->string('sku', 32)->index();
            $t->string('title', 120);
            $t->string('source', 12)->default('ai');
            // Чем сгенерировано и от какого исходного имени — чтобы понимать,
            // устарел ли заголовок после переименования позиции в каталоге.
            $t->string('model', 40)->nullable();
            $t->string('source_name', 255)->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_ad_titles');
    }
};
