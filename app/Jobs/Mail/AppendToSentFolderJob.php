<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\MailboxConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async IMAP APPEND отправленного письма в папку Sent ящика (Phase 1.9).
 *
 * SMTP send уже произошёл синхронно в OutgoingMailSender. Письмо для клиента
 * доставлено. APPEND нужен чтобы в Yandex web UI оператор видел Sent-копию
 * (и чтобы догоняющий Sent-sync сразу дедупил по message_id, не пытаясь
 * создать новую EmailMessage).
 *
 * Retry x3 с backoff. Если упало финально — письмо в треде CRM остаётся
 * (наш source of truth), а Sent-копия попадёт позже через догоняющий sync
 * (Yandex SMTP→Sent опционально автоматически кладёт письма).
 */
class AppendToSentFolderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /** @return int[] */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly int $emailMessageId,
        public readonly string $rawMime,
    ) {
    }

    public function handle(MailboxConnector $connector): void
    {
        $message = EmailMessage::with('mailbox')->find($this->emailMessageId);
        if ($message === null) {
            Log::warning('AppendToSentFolderJob: message not found', [
                'email_message_id' => $this->emailMessageId,
            ]);
            return;
        }
        if ($message->is_draft) {
            Log::warning('AppendToSentFolderJob: skipping draft', [
                'email_message_id' => $message->id,
            ]);
            return;
        }
        if ($message->imap_uid !== null) {
            // Уже сматчилось через Sent-sync. Идемпотентность.
            return;
        }
        $mailbox = $message->mailbox;
        if ($mailbox === null || ! $mailbox->canSendOutbound()) {
            Log::warning('AppendToSentFolderJob: mailbox not available', [
                'email_message_id' => $message->id,
                'mailbox_id' => $mailbox?->id,
            ]);
            return;
        }

        $client = $connector->imapClient($mailbox);
        try {
            $sent = $connector->findSent($client);
            $internalDate = $message->sent_at ?: now();

            // appendMessage возвращает validatedData() — обычно массив
            // с ответом сервера. APPENDUID может быть распарсен, но
            // webklex абстрагирует — мы получим финальный UID при следующем
            // Sent-sync через дедуп по message_id.
            $sent->appendMessage($this->rawMime, ['\\Seen'], $internalDate);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // ignore disconnect errors
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('AppendToSentFolderJob: final failure', [
            'email_message_id' => $this->emailMessageId,
            'error' => $e->getMessage(),
        ]);
    }
}
