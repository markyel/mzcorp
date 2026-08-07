<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Еженедельные персональные отчёты менеджеров. Хранят ЗАМОРОЖЕННЫЙ снимок
 * метрик за неделю (data jsonb) — просрочки/зависшие фиксируются на момент
 * генерации, поэтому отчёт неизменен и прошлые недели остаются как были.
 * Рендер страницы/письма — из data. Генерация: reports:weekly-generate
 * (пн 08:00 МСК), рассылка на почту менеджера.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('weekly_reports')) {
            return;
        }
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->jsonb('data');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_start']);
            $table->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
