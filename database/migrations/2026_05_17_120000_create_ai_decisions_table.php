<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 / Foundation §7 — DocumentDetector audit + validation framework.
 *
 * Журнал AI-решений детектора (outbound КП/счёт, inbound client-response
 * classifier). Используется для:
 *  - UI suggestion-prompt: «AI предположил X — применить?» (status=suggested);
 *  - Audit: какое решение AI принял, что сделал оператор;
 *  - Validation framework (Foundation §7.3): счётчики
 *    auto_applied/manually_overridden/dismissed — Settings allow auto-mode
 *    per type когда error_rate < target.
 *
 * Один Email может породить несколько решений (например outbound с КП ДА
 * вопрос «уточните...» = два detected_artifacts).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_decisions')) {
            return;
        }

        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->id();

            // detector_type — enum App\Enums\DetectorType (outbound_quotation_full /
            // outbound_invoice / outbound_clarification / inbound_under_review /
            // inbound_postponed / inbound_invoice_request / inbound_decline /
            // inbound_clarification_response / inbound_unclear).
            $table->string('detector_type', 64)->index();

            // status enum: suggested | auto_applied | manually_confirmed |
            // manually_overridden | dismissed | failed.
            $table->string('status', 32)->default('suggested')->index();

            // Связи. Заявка — обязательно (иначе детектор не должен был запуститься).
            $table->foreignId('request_id')
                ->constrained('requests')->cascadeOnDelete();
            $table->foreignId('email_message_id')
                ->constrained('email_messages')->cascadeOnDelete();

            // Confidence 0..1 (float). Используется в Settings-threshold gate.
            $table->float('confidence')->nullable();

            // Полезная нагрузка: matched_signals, extracted_date,
            // extracted_quote, suggested_status_value, и т.п.
            $table->jsonb('payload')->nullable();

            // Аудит применения.
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            // Если оператор переопределил — что он выбрал вместо AI.
            $table->string('override_to_status', 40)->nullable();

            $table->timestamps();

            $table->index(['request_id', 'status']);
            $table->index(['detector_type', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
    }
};
