<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.10 — audit-лог переходов статусов заявки (Foundation §852).
 *
 * Дополняет `request_assignments` (только audit назначений) полным
 * audit'ом status-переходов:
 *  - ручные через UI (event='manual')
 *  - cron resume из paused (event='auto_resume_pause')
 *  - инициальное создание заявки (event='system_initial')
 *  - будущее: автодетекторы КП/счетов (event='auto_detect_quote' / 'auto_detect_invoice')
 *
 * В UI таб «Активность» отображает merge этой таблицы с request_assignments
 * по created_at.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('request_state_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('from_status', 40)->nullable()
                ->comment('Null для initial-event (создание заявки).');
            $table->string('to_status', 40);
            $table->foreignId('by_user_id')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('Null если переход системный (cron / detector).');
            $table->string('event', 32)->default('manual')
                ->comment('manual | auto_resume_pause | system_initial | auto_detect_*');
            $table->text('comment')->nullable();
            $table->jsonb('payload')->nullable()
                ->comment('Доп. контекст: closed_lost_reason, pause-параметры, detector-confidence.');
            $table->timestamps();

            $table->index(['request_id', 'created_at']);
            $table->index(['to_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_state_changes');
    }
};
