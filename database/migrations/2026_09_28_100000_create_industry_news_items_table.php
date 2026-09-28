<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новости отрасли из RSS — копим у себя.
 *
 * Лента liftpages.ru отдаёт только 30 последних новостей, это 4–5 дней: к
 * пятничному дайджесту начало недели из неё уже выпадает. Поэтому ленту
 * читаем несколько раз в сутки и складываем сюда, а дайджест собирается
 * отсюда за ровную неделю.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('industry_news_items')) {
            return;
        }

        Schema::create('industry_news_items', function (Blueprint $table) {
            $table->id();
            // guid новости из ленты (или ссылка, если guid нет) — ключ дедупа.
            $table->string('guid', 500)->unique();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('link');
            $table->timestamp('published_at')->index();
            $table->string('feed_url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_news_items');
    }
};
