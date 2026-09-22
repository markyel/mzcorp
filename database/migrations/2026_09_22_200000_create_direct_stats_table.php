<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статистика Директа по дням: что именно принесло показы.
 *
 * Два разреза в одной таблице. `criteria` — условие показа: наша фраза или
 * автотаргетинг; по нему видно, работают ли артикулы или весь трафик тянет
 * подбор Яндекса. `query` — реальный поисковый запрос человека; это единственный
 * источник правды о том, как деталь называют вслух, и лучший материал для
 * новых фраз.
 *
 * Живой счётчик кампании и отчёты расходятся на несколько часов — отчёты
 * догоняют. Поэтому строки перезаписываются по ключу, а не копятся.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_stats')) {
            return;
        }

        Schema::create('direct_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            // criteria — условие показа, query — поисковый запрос.
            $table->string('kind', 16)->index();
            // KEYWORD / AUTOTARGETING — как их называет сам Директ.
            $table->string('criteria_type', 32)->nullable();
            $table->string('name', 500);
            // Для запроса — фраза или автотаргетинг, по которым он пришёл.
            $table->string('matched', 500)->nullable();
            $table->string('sku', 32)->nullable()->index();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'kind', 'name', 'matched', 'criteria_type'], 'direct_stats_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_stats');
    }
};
