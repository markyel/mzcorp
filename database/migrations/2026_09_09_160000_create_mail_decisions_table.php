<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал решений маршрутизатора по каждому письму: на каком шаге MailRouter
 * закончил обработку и с каким исходом. До этого 15 из 19 выходов route()
 * не оставляли следа (разбор 2026-09-09) — разбор «почему письмо ушло сюда»
 * требовал чтения логов за день. Одна строка на один проход route()
 * (повторные проходы кронов дают новые строки — это история).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_decisions')) {
            return;
        }
        Schema::create('mail_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();
            // Шаг маршрутизатора (MailDecisionRecorder::STAGES) и исход.
            $table->string('stage', 64);
            $table->string('outcome', 32);
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            // Категория письма на момент решения (после классификатора / override'ов).
            $table->string('category', 32)->nullable();
            // Человекочитаемое объяснение для карточки письма.
            $table->string('reason', 500)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email_message_id', 'created_at']);
            $table->index(['stage', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_decisions');
    }
};
