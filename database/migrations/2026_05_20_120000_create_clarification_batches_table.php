<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation §6.2 + LazyLift ClarificationRequest — структурированные
 * уточняющие вопросы клиенту.
 *
 * Менеджер в табе «Позиции» накапливает вопросы (по конкретным items
 * или общие), формирует одно письмо. После отправки заявка автоматически
 * переходит в `awaiting_client_clarification`, и при ответе клиента мы
 * сможем сматчить ответы на вопросы для обогащения KB.
 *
 * batches (parent) + questions (children, 1:N). Один batch = одно
 * исходящее письмо с N вопросами.
 *
 * Status flow:
 *   drafted  → формируется в UI, есть draft email
 *   sent     → отправлено (после ComposeForm::send + hook)
 *   answered → клиент ответил (Phase B — answer matching)
 *   cancelled → менеджер откатил draft / Не отправил
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('clarification_batches')) {
            Schema::create('clarification_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->string('status', 16)->default('drafted')->index();
                $table->text('general_question')->nullable();

                // Связь с email_messages для тех или иных стадий.
                $table->foreignId('draft_email_id')->nullable()
                    ->constrained('email_messages')->nullOnDelete();
                $table->foreignId('sent_message_id')->nullable()
                    ->constrained('email_messages')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('answered_at')->nullable();

                $table->timestamps();

                $table->index(['request_id', 'status']);
            });
        }

        if (! Schema::hasTable('clarification_questions')) {
            Schema::create('clarification_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('clarification_batches')->cascadeOnDelete();
                $table->foreignId('request_item_id')->nullable()
                    ->constrained('request_items')->cascadeOnDelete();
                $table->text('question');
                $table->text('answer')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->foreignId('answered_via_message_id')->nullable()
                    ->constrained('email_messages')->nullOnDelete();
                $table->timestamps();

                $table->index(['batch_id']);
                $table->index(['request_item_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clarification_questions');
        Schema::dropIfExists('clarification_batches');
    }
};
