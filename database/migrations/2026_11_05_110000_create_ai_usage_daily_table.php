<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Расход ИИ по дням: модель × кто вызвал (сервис) × канал (основной OpenAI / резерв Beget).
        // Копится счётчиками на каждом вызове — без журнала по запросу. См. AiUsageMeter, ai:usage.
        if (! Schema::hasTable('ai_usage_daily')) {
            Schema::create('ai_usage_daily', function (Blueprint $table) {
                $table->id();
                $table->date('day');
                $table->string('model', 64);
                $table->string('source', 120);
                $table->string('channel', 16)->default('primary');
                $table->unsignedInteger('calls')->default(0);
                $table->unsignedBigInteger('prompt_tokens')->default(0);
                $table->unsignedBigInteger('completion_tokens')->default(0);
                $table->unsignedBigInteger('cached_tokens')->default(0);
                $table->timestamps();

                $table->unique(['day', 'model', 'source', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
