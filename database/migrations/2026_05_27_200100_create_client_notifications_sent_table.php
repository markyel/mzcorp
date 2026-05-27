<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История отправленных автоматических уведомлений клиенту.
 *
 * Цели:
 *  - Идемпотентность: для типов вроде OrderReceived — не слать дважды
 *    (uniq на request_id+type+scope_key).
 *  - Аудит: видеть кто/когда/что отправлено клиенту по каждой заявке.
 *  - Cron-логика: «знать что reminder уже отправляли, не слать снова до
 *    следующего значимого изменения» (например, после reply клиента
 *    счётчик reminder'ов обнуляется).
 *
 * scope_key — дополнительный ключ для типов, которые можно слать
 * многократно с разным «контекстом»:
 *  - clarification_reminder → scope_key = clarification_batch.id
 *  - invoice_expiring_soon → scope_key = invoice.id + '#' + days_until
 *  - quote_followup_reminder → scope_key = quote message_id
 *
 * Для one-shot типов (OrderReceived) scope_key = '' и uniq режет
 * повторные отправки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_notifications_sent')) {
            return;
        }

        Schema::create('client_notifications_sent', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('type', 64); // ClientNotificationType value
            $table->string('scope_key', 200)->default(''); // см. docblock
            // Письмо OUTBOUND, которое мы отправили (полезно для треда + аудита).
            $table->unsignedBigInteger('outgoing_email_message_id')->nullable();
            // Письмо клиента (тред-anchor) на которое мы ответили.
            $table->unsignedBigInteger('reply_to_email_message_id')->nullable();
            $table->string('recipient_email', 320);
            $table->string('subject', 500);
            $table->text('body_rendered_html')->nullable();
            $table->text('body_rendered_plain')->nullable();
            $table->timestamp('sent_at');
            // Кто инициировал (user_id если manual override; null если cron/system).
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'type', 'scope_key'], 'cns_uniq_request_type_scope');
            $table->index(['type', 'sent_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('client_notifications_sent')) {
            Schema::drop('client_notifications_sent');
        }
    }
};
