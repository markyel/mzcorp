<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статистика по ВСЕМ кампаниям аккаунта и разбор поисковых запросов.
 *
 * Токен выдан на аккаунт целиком, а мусор ловится там же, где показы: запрос
 * «опорный поручень для ванной комнаты» пришёл в нашу кампанию, а «блок
 * питания» может прийти в любую. Поэтому в статистике появляется кампания, а
 * у каждого запроса — вердикт: наш он или чужой, и какую минус-фразу из него
 * взять. Решение человека (исключить / оставить) хранится рядом с вердиктом,
 * чтобы один и тот же запрос не разбирался заново каждый день.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('direct_stats', 'campaign_id')) {
            Schema::table('direct_stats', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('direct_query_reviews')) {
            Schema::create('direct_query_reviews', function (Blueprint $table) {
                $table->id();
                $table->string('query', 500)->unique();
                $table->unsignedBigInteger('campaign_id')->nullable()->index();
                // ours | foreign | unclear — как решила модель.
                $table->string('verdict', 16)->index();
                // Минус-фраза, которую модель предлагает вычесть из показов.
                $table->string('phrase', 200)->nullable();
                $table->string('reason', 500)->nullable();
                $table->string('model', 64)->nullable();
                // null — решения человека ещё нет; excluded | kept — есть.
                $table->string('decision', 16)->nullable()->index();
                $table->timestamp('decided_at')->nullable();
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('impressions')->default(0);
                $table->unsignedInteger('clicks')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_query_reviews');

        if (Schema::hasColumn('direct_stats', 'campaign_id')) {
            Schema::table('direct_stats', function (Blueprint $table) {
                $table->dropColumn('campaign_id');
            });
        }
    }
};
