<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Письма (входящие и исходящие).
 *
 * Foundation §1 «Идемпотентность и устойчивость»:
 *   «Уникальный ключ — Message-ID + mailbox_id + folder. Одно письмо может
 *    лежать в Inbox у получателя и в Sent у отправителя — это разные
 *    EmailMessage, но request связь общая.»
 *
 * Поля related_request_id, ai_classification, classified_at, detected_artifacts
 * заполняются на следующих фазах (1.6, 1.8, Phase 4 — DocumentDetector).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();

            // Источник
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('folder', 32)->comment('Inbox | Sent');
            $table->string('direction', 16)->index()->comment('inbound | outbound — App\Enums\MailDirection');
            $table->unsignedBigInteger('imap_uid')->comment('UID письма в IMAP-папке');

            // Идентификация письма (RFC 5322)
            $table->string('message_id', 998)->comment('Заголовок Message-ID без угловых скобок');
            $table->string('in_reply_to', 998)->nullable();
            $table->jsonb('references_header')->nullable()->comment('Массив Message-ID из References');

            // Заголовки
            $table->string('subject', 998)->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->jsonb('to_recipients')->nullable()->comment('Массив { email, name }');
            $table->jsonb('cc_recipients')->nullable();
            $table->timestamp('sent_at')->nullable()->comment('Заголовок Date');

            // Тело
            $table->longText('body_plain')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('raw_source')->nullable()->comment('Полный MIME — для отладки парсера');
            $table->jsonb('headers')->nullable()->comment('Все заголовки целиком');
            $table->jsonb('imap_flags')->nullable()->comment('IMAP-флаги (включая custom labels MyLift/...)');

            // AI и связи (заполняются позднее)
            $table->string('ai_classification', 32)->nullable()->index()
                ->comment('request | reclamation | accounting | general_question | spam | other');
            $table->float('ai_classification_confidence')->nullable();
            $table->timestamp('classified_at')->nullable()->comment('Идемпотентность: NULL = не обработано AI');

            $table->jsonb('detected_artifacts')->nullable()
                ->comment('Что распознал DocumentDetector в outbound (КП/счёт) — Phase 4');

            // Связь с заявкой (Phase 1.8)
            $table->unsignedBigInteger('related_request_id')->nullable()->index();
            // FK на requests добавим когда создадим таблицу requests

            $table->timestamps();

            // Foundation: уникальность по (message_id, mailbox_id, folder)
            $table->unique(['mailbox_id', 'folder', 'message_id'], 'email_messages_unique_per_folder');
            $table->index(['mailbox_id', 'folder', 'imap_uid'], 'email_messages_uid_lookup');
            $table->index('from_email');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
