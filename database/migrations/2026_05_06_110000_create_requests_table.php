<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявка клиента (Foundation: «system of record для заявки внутри MyLift»).
 *
 * Phase 1 минимум: код + ссылка на исходное письмо + назначенный менеджер +
 * статус. Все KB-расширения (status enum machine, paused_*, attention_*,
 * corp_external_code, closed_lost_*) — Phase 4.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code')->unique()
                ->comment('M-2026-0001 — внутренний код, генерируется при создании');

            $table->foreignId('email_message_id')->nullable()
                ->constrained('email_messages')->nullOnDelete()
                ->comment('Исходное входящее письмо, из которого создана заявка');

            $table->foreignId('assigned_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('status', 32)->default('new')->index()
                ->comment('App\\Enums\\RequestStatus — на Phase 1 только new|assigned');

            // Денормализация для быстрого отображения в списках без JOIN-ов.
            $table->string('client_email')->index();
            $table->string('client_name')->nullable();
            $table->string('subject', 998)->nullable();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->index('assigned_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
