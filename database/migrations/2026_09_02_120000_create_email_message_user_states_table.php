<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздел «Почта» (клиент менеджера): состояние письма НА ПОЛЬЗОВАТЕЛЯ —
 * прочитано / помечено флагом.
 *
 * Почему отдельная таблица, а не IMAP \Seen: по правилу проекта (CLAUDE.md §8)
 * флаг \Seen при чтении/разборе НЕ ставится (READ-ONLY, FT_PEEK). Значит
 * «прочитано» в интерфейсе — это app-level состояние на связке (письмо × юзер),
 * не затрагивающее сервер. Флаг (⚑) — тоже персональный, живёт здесь же.
 *
 * Одна запись на (email_message_id, user_id). Отсутствие записи ⇒ письмо
 * непрочитано и без флага. read_at/flagged_at nullable — можно снять любое.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_message_user_states')) {
            return;
        }

        Schema::create('email_message_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->timestamps();

            $table->unique(['email_message_id', 'user_id'], 'email_msg_user_states_unique');
            // Быстрый подсчёт непрочитанного/помеченного на пользователя.
            $table->index(['user_id', 'read_at'], 'email_msg_user_states_user_read_idx');
            $table->index(['user_id', 'flagged_at'], 'email_msg_user_states_user_flag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message_user_states');
    }
};
