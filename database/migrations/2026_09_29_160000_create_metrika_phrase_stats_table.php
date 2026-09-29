<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Метрика: визиты и цели по фразе Директа и поисковому запросу.
 *
 * Разрез по кампании (metrika_daily_stats) говорит, какая кампания приносит
 * обращения, но не какая фраза. Здесь — условие показа (фраза или
 * «Автотаргетинг») и сам запрос человека: у автотаргетинга только он и
 * объясняет, откуда пришёл визит.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('metrika_phrase_stats')) {
            return;
        }

        Schema::create('metrika_phrase_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->unsignedBigInteger('counter_id');
            $table->unsignedBigInteger('campaign_id')->index();
            // Фраза как в Директе (с минус-словами) или «Автотаргетинг».
            $table->string('condition', 1000);
            // Поисковый запрос; null — Метрика его не знает (сети, скрытые запросы).
            $table->string('search_query', 1000)->nullable();
            // md5(condition|search_query) — ключ строки внутри дня и кампании.
            $table->string('row_hash', 32);
            $table->unsignedInteger('visits')->default(0);
            // {goal_id: достижений}
            $table->jsonb('goals')->nullable();
            $table->timestamps();

            $table->unique(['date', 'counter_id', 'campaign_id', 'row_hash'], 'metrika_phrase_stats_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrika_phrase_stats');
    }
};
