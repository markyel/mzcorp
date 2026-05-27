<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-таблица для всех IMAP APPEND попыток (cross-mailbox delivery).
 *
 * Зачем: пользователь жалуется на дубли писем в личных Yandex-ящиках
 * менеджеров. Анализ 2026-05-27 показал 36 случаев cross-mailbox дублей
 * без artifact `email_messages.detected_artifacts.inbox_deliveries[]` —
 * формально «не наш APPEND», но без trace доказать это нельзя (может быть
 * silent failure между appendMessage и save() artifact'а).
 *
 * Эта таблица пишется в начале каждого вызова MailDeliverToManagerService::deliver
 * и обновляется на финальный статус (success/skip/failed). Через несколько дней
 * сверка с появившимися yandex_side дублями даст 100% ответ: наш ли APPEND
 * или внешний источник (Yandex distribution-group / личный фильтр менеджера).
 *
 * Записи можно периодически чистить — это не операционная таблица, а диагностика.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_append_audit')) {
            return;
        }

        Schema::create('mail_append_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_message_id'); // origin message (source)
            $table->unsignedBigInteger('target_user_id'); // кому пытаемся доставить
            $table->unsignedBigInteger('target_mailbox_id')->nullable(); // personal mailbox менеджера (если найден)
            $table->unsignedBigInteger('origin_mailbox_id')->nullable(); // где лежит исходник
            $table->string('message_id_rfc', 998)->nullable(); // RFC822 Message-ID — для match'а с появившимися дублями
            $table->string('subject', 500)->nullable();
            $table->string('status', 32); // pending / success / skipped / failed
            $table->string('skip_reason', 64)->nullable(); // already_in_manager_mailbox / origin_in_another_personal / already_delivered / no_personal_mailbox / empty_raw / etc.
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('email_message_id');
            $table->index('target_user_id');
            $table->index(['status', 'created_at']);
            $table->index('message_id_rfc');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('mail_append_audit')) {
            Schema::drop('mail_append_audit');
        }
    }
};
