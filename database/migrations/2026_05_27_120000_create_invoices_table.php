<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Invoice tracking.
 *
 * Счёт выставляется ВНЕ системы (1С), мы только трекаем:
 *   - номер счёта, дату выставления, срок действия (5 раб.дней default),
 *   - статус (pending / paid / expired / cancelled),
 *   - кто выставил, кто оплатил, когда оплатил.
 *
 * Cron `invoices:check-expiry` ежедневно отмечает просроченные счета
 * как `expired` и возвращает Request в `awaiting_invoice` для re-issue.
 *
 * Одна Request → много Invoice (history всех попыток + re-issue после expiry).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            return;
        }

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('validity_days');
            // pending | paid | expired | cancelled
            $table->string('status', 16)->default('pending');

            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->text('comment')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();

            // Snapshot total из связанной Quotation на момент issue (для UI).
            $table->decimal('amount_snapshot', 14, 2)->nullable();

            $table->timestamps();

            $table->index(['request_id', 'status']);
            $table->index(['status', 'expires_at']);    // для cron
            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
