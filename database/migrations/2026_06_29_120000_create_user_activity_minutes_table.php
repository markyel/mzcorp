<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heartbeat-присутствие пользователей: одна строка = одна «активная минута».
 *
 * Фронтенд шлёт лёгкий ping каждые ~60с, пока вкладка видима. Контроллер
 * insertOrIgnore'ит текущую минуту (усечённый timestamp). Уникальность
 * (user_id, minute) даёт естественный дедуп: несколько вкладок / повторные
 * пинги в ту же минуту не раздувают таблицу.
 *
 * «Время в системе» за день = COUNT(*) строк пользователя за дату (MSK).
 * Хранение MSK-naive (как все timestamps проекта, app.timezone=Europe/Moscow),
 * поэтому группировка — DATE(minute) без конвертаций.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_activity_minutes')) {
            return;
        }

        Schema::create('user_activity_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('minute')->comment('Активная минута присутствия (усечено до минуты, MSK)');

            // Дедуп: один пользователь — одна запись на минуту.
            $table->unique(['user_id', 'minute'], 'user_activity_minutes_unique');
            // Диапазонные выборки за период + прунинг по дате.
            $table->index('minute');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_minutes');
    }
};
