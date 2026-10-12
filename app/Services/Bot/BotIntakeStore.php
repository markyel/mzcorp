<?php

namespace App\Services\Bot;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MessengerChat;
use App\Models\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Файлы и текст заявки из бота в карточке заявки.
 *
 * Вкладки «Переписка» и «Файлы», галерея фото, экспорт в PDF, права на
 * скачивание — всё в mzCorp построено на письмах и их вложениях. Поэтому
 * заявка из бота получает «письмо»-носитель: входящее от клиента с его
 * текстом и всеми присланными фото и файлами. Носитель лежит в служебном
 * НЕАКТИВНОМ ящике без IMAP — синхронизация и почта пользователей его не
 * видят (Mailbox::syncable требует is_active), а ответ на него уходит из
 * ящика менеджера заявки (EmailDraftService выбирает ящик по заявке).
 */
class BotIntakeStore
{
    public const DISK = 'local';

    public const MAILBOX_EMAIL = 'bot-intake@mzcorp.ru';

    /** Файлов в одной заявке и размер одного. */
    public const MAX_FILES = 20;

    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    public function mailbox(): Mailbox
    {
        return Mailbox::query()->firstOrCreate(['email' => self::MAILBOX_EMAIL], [
            'name' => 'Боты заявок (служебный, без IMAP — не включать)',
            'type' => MailboxType::Shared->value,
            'imap_username' => self::MAILBOX_EMAIL,
            'smtp_username' => self::MAILBOX_EMAIL,
            'encrypted_credentials' => Crypt::encryptString('{}'),
            'is_active' => false,
        ]);
    }

    /**
     * Сохранить файл клиента до отправки заявки (черновик).
     *
     * @return array{path: string, name: string, mime: ?string, size: int}
     */
    public function storeDraftFile(MessengerChat $chat, string $bytes, string $name, ?string $mime): array
    {
        $name = trim($name) !== '' ? mb_substr($name, 0, 200) : 'file';
        $path = sprintf('bot-intake/%d/%s_%s', $chat->id, Str::random(8), self::safeName($name));
        Storage::disk(self::DISK)->put($path, $bytes);

        return ['path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes)];
    }

    /** @param  list<array{path: string}>  $files */
    public function discard(array $files): void
    {
        foreach ($files as $f) {
            if (! empty($f['path']) && str_starts_with((string) $f['path'], 'bot-intake/')) {
                Storage::disk(self::DISK)->delete($f['path']);
            }
        }
    }

    /**
     * «Письмо»-носитель заявки: текст клиента и все его файлы.
     *
     * @param  list<array{path: string, name: string, mime: ?string, size: int}>  $files
     */
    public function carrier(Request $request, MessengerChat $chat, string $text, array $files): EmailMessage
    {
        $message = EmailMessage::create([
            'mailbox_id' => $this->mailbox()->id,
            'folder' => 'BOT',
            'direction' => MailDirection::Inbound->value,
            'imap_uid' => null,
            'is_draft' => false,
            'message_id' => 'bot-'.$request->id.'@mzcorp.ru',
            'subject' => (string) $request->subject,
            'from_email' => (string) $chat->email,
            'from_name' => $request->client_name,
            'to_recipients' => [],
            'sent_at' => now(),
            'body_plain' => $text !== '' ? $text : '(без текста — см. вложения)',
            'headers' => ['x_mylift_bot_request' => $chat->channel->value],
            'related_request_id' => $request->id,
            'category' => 'client_request',
            'categorized_at' => now(),
            'detected_artifacts' => ['bot_request' => $chat->channel->value],
        ]);

        foreach ($files as $f) {
            if (! Storage::disk(self::DISK)->exists($f['path'])) {
                continue;
            }
            EmailAttachment::create([
                'email_message_id' => $message->id,
                'filename' => $f['name'],
                'mime_type' => $f['mime'] ?: 'application/octet-stream',
                'size_bytes' => (int) $f['size'],
                'file_path' => $f['path'],
                'disk' => self::DISK,
                'is_inline' => false,
            ]);
        }

        return $message->load('attachments');
    }

    private static function safeName(string $name): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($safe, '_') ?: 'file', 0, 120);
    }
}
