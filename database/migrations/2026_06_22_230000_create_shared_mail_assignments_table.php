<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаринг почты выбывших менеджеров (Phase: shared-mail). Раздел «Почта
 * выбывших» / «Почта»: письма из ящиков недоступных менеджеров, НЕ привязанные
 * к заявкам. РОП/директор назначают ответственного; он отвечает со своего ящика.
 * Состояние (назначение + прочитанность) держим в side-таблице, а сам список —
 * живой запрос по email_messages (без материализации).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shared_mail_assignments')) {
            return;
        }
        Schema::create('shared_mail_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->unique()->constrained('email_messages')->cascadeOnDelete();
            // Ответственный менеджер (назначает РОП/директор). null — ещё не назначен.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            // Единый флаг прочитанности (можно сбросить).
            $table->timestamp('read_at')->nullable();
            $table->foreignId('read_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_mail_assignments');
    }
};
