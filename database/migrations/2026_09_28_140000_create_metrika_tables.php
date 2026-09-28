<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Яндекс Метрика: цели счётчиков и дневная статистика визитов.
 *
 * Директ знает, сколько стоил клик, но не что человек сделал на сайте. Метрика
 * знает обратное — и видит в одном счётчике и наши кампании, и агентские.
 * Поэтому здесь визиты и достижения целей по кампаниям Директа (kind =
 * direct_campaign) и по источникам трафика в целом (kind = source).
 *
 * Достижения храним по всем целям сразу, в jsonb: что считать «заявкой» —
 * решение, которое меняется, и перевыгружать ради него историю незачем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metrika_goals')) {
            Schema::create('metrika_goals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('counter_id')->index();
                $table->unsignedBigInteger('goal_id');
                $table->string('name', 500);
                $table->string('type', 64)->nullable();
                $table->timestamps();

                $table->unique(['counter_id', 'goal_id']);
            });
        }

        if (! Schema::hasTable('metrika_daily_stats')) {
            Schema::create('metrika_daily_stats', function (Blueprint $table) {
                $table->id();
                $table->date('date')->index();
                $table->unsignedBigInteger('counter_id');
                // direct_campaign — кампания Директа, source — источник трафика.
                $table->string('kind', 32);
                // Номер кампании Директа или код источника.
                $table->string('key', 64);
                $table->string('name', 500);
                $table->unsignedInteger('visits')->default(0);
                $table->decimal('bounce_rate', 5, 2)->nullable();
                $table->decimal('page_depth', 6, 2)->nullable();
                $table->unsignedInteger('avg_visit_seconds')->nullable();
                // {goal_id: достижений}
                $table->jsonb('goals')->nullable();
                $table->timestamps();

                $table->unique(['date', 'counter_id', 'kind', 'key'], 'metrika_daily_stats_key');
                $table->index(['kind', 'key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('metrika_daily_stats');
        Schema::dropIfExists('metrika_goals');
    }
};
