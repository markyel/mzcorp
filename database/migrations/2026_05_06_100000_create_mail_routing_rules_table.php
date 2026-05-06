<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила маршрутизации входящих писем.
 *
 * Foundation §1.5: настраиваемые правила для перенаправления писем
 * (рекламации, бухгалтерия, общие вопросы) без участия секретаря.
 *
 * Применение: для каждого нового inbound EmailMessage проходим правила
 * в порядке priority. Первое совпавшее с is_terminal=true останавливает
 * цепочку. Применённое правило фиксируется в routed_mails (audit).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Человекочитаемое имя для UI: "Рекламации", "Бухгалтерия"');
            $table->integer('priority')->default(100)->index()
                ->comment('Меньше = раньше. Применяем по возрастанию');
            $table->boolean('is_active')->default(true)->index();

            // На какие ящики применять. NULL = все активные.
            $table->jsonb('mailbox_scope')->nullable()
                ->comment('Массив mailbox_id или null для всех');

            $table->string('match_mode', 32)
                ->comment('any_of | all_of | ai_classified — App\Enums\MailRuleMatchMode');

            $table->jsonb('match_criteria')->nullable()
                ->comment('Массив критериев [{field, op, values}] — игнорируется при ai_classified');

            $table->string('ai_match_type', 32)->nullable()
                ->comment('При match_mode=ai_classified: request | reclamation | accounting | general_question | spam');

            $table->string('action_type', 48)
                ->comment('forward | label_only | trigger_request_creation — App\Enums\MailRuleActionType');

            $table->string('forward_to_email')->nullable()
                ->comment('Адрес для пересылки при action_type=forward');

            $table->string('label')->nullable()
                ->comment('Имя IMAP custom-флага, например MyLift/Рекламации');

            $table->boolean('is_terminal')->default(true)
                ->comment('Если true — после срабатывания не проверяем дальнейшие правила');

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->unsignedInteger('match_count')->default(0)
                ->comment('Сколько раз правило срабатывало (для метрик в UI)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_routing_rules');
    }
};
