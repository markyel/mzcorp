<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обучаемые привязки артикулов (идея заказчика, 2026-07-06): когда менеджер
 * вручную привязывает позицию с нераспознанным кодом к каталожной M-позиции,
 * запоминаем соответствие «код → catalog_item». При повторных подтверждениях
 * автоматчинг начинает использовать выученный алиас (шаг D, после точных A/B).
 * Каталог 1С при этом не трогаем — словарь живёт на нашей стороне.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learned_article_aliases')) {
            return;
        }
        Schema::create('learned_article_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('article_normalized', 64)->index();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('confirmations')->default(1);
            $table->string('sample_article', 190)->nullable();
            $table->string('sample_name', 190)->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->foreignId('last_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['article_normalized', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('learned_article_aliases')) {
            Schema::drop('learned_article_aliases');
        }
    }
};
