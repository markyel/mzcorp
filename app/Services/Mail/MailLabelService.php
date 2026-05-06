<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * Постановка IMAP custom flags (labels) на письма.
 *
 * Foundation §1.6: «секретарь, открывая обычный почтовый клиент Яндекс,
 * видит у каждого письма метку и сразу понимает...»
 *
 * Технические детали:
 *   - Lable вешаем как кастомный IMAP keyword, например `MyLift/Рекламации`.
 *   - Yandex IMAP принимает custom keywords в STORE +FLAGS.
 *   - НЕ ставим `\Seen` — Foundation §1 явно требует, чтобы письма
 *     оставались непрочитанными в Яндекс веб-клиенте. Поэтому открываем
 *     папку через examine (READ-ONLY) для чтения, а для STORE +FLAGS —
 *     отдельный SELECT в read-write через webklex.
 */
class MailLabelService
{
    public function __construct(private readonly MailboxConnector $connector)
    {
    }

    /**
     * Поставить label на письмо. Также сохраняет лейбл в EmailMessage.imap_flags
     * для отображения в нашем UI.
     *
     * @return bool true = label успешно поставлен.
     */
    public function applyLabel(EmailMessage $message, string $label): bool
    {
        $mailbox = $message->mailbox;
        if (! $mailbox) {
            return false;
        }

        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $folder = $client->getFolderByPath($message->folder, soft_fail: true);
            if (! $folder) {
                throw new \RuntimeException("Folder {$message->folder} not found");
            }

            // Получаем сообщение по UID. webklex переоткроет папку в read-write
            // автоматически при STORE +FLAGS.
            $msgs = $folder->query()
                ->setFetchOptions(IMAP::FT_PEEK)
                ->setFetchBody(false)
                ->setFetchFlags(true)
                ->whereUid($message->imap_uid)
                ->get();

            $msg = $msgs->first();
            if (! $msg) {
                throw new \RuntimeException("Message UID {$message->imap_uid} not found in folder");
            }

            // addFlag принимает в т.ч. кастомные keyword'ы. Yandex использует
            // формат с обратным слэшем для системных и без — для custom.
            // MyLift/* — это просто custom keyword.
            $msg->addFlag($label);

            // Обновляем нашу копию imap_flags — для UI и отчётов.
            $flags = (array) ($message->imap_flags ?? []);
            if (! in_array($label, $flags, true)) {
                $flags[] = $label;
                $message->imap_flags = $flags;
                $message->save();
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to apply IMAP label', [
                'mailbox_id' => $mailbox->id,
                'message_id' => $message->id,
                'label' => $label,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $client?->disconnect();
        }
    }
}
