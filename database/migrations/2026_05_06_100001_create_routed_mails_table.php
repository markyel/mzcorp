<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит срабатываний правил маршрутизации.
 *
 * Foundation §«Новые модели»:
 *   RoutedMail (аудит: какое письмо по какому правилу куда ушло —
 *   email_message_id, rule_id nullable, ai_classified_as nullable,
 *   action_taken, forwarded_to nullable, label_applied, processed_at)
 *
 * Одно письмо может порождать несколько записей, если оно прошло через
 * non-terminal правила (например, навешали несколько меток).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('routed_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')
                ->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('rule_id')
                ->nullable()
                ->constrained('mail_routing_rules')->nullOnDelete()
                ->comment('NULL для fallback / AI-классификации без явного правила');

            $table->string('ai_classified_as', 32)->nullable()
                ->comment('request | reclamation | ... — когда срабатывало через AI');

            $table->string('action_taken', 48)
                ->comment('forward | label_only | trigger_request_creation | none');

            $table->string('forwarded_to')->nullable();
            $table->string('label_applied')->nullable();

            $table->boolean('success')->default(true)
                ->comment('false при ошибке forward/label — деталь в error_message');
            $table->text('error_message')->nullable();

            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            $table->index(['email_message_id', 'rule_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routed_mails');
    }
};
