<?php

namespace App\Services\Bot;

use App\Models\EmailAttachment;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Копия файлов из переписки в мессенджере — письмом на почту клиента (17.11.2026, MZ2026-01628).
 *
 * Бот обещает клиенту КП и счета на подтверждённый e-mail, а менеджер присылает их файлом в чат —
 * клиент ждал письма, которого не было. Галочка «копию на почту» в окне переписки: письмо уходит
 * с ящика менеджера обычным путём (черновик → отправка), ложится в переписку заявки и проходит
 * обычное распознавание КП/счетов.
 */
class BotDocumentMailer
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
    ) {
    }

    /**
     * @param  list<array{bytes: string, name: string, mime: ?string}>  $files
     * @return array{ok: bool, message: string}
     */
    public function send(Request $request, User $by, string $text, array $files): array
    {
        $email = trim((string) $request->client_email);
        if ($email === '' || $files === []) {
            return ['ok' => false, 'message' => 'Копия на почту не отправлена: нет e-mail клиента.'];
        }

        $draft = null;
        try {
            $draft = $this->drafts->createCompose($request, $by);
            $this->drafts->update($draft, [
                'subject' => self::subject($request, $files),
                'to_recipients' => [['email' => $email, 'name' => (string) ($request->client_name ?? '')]],
                'cc_recipients' => [],
                'body_plain' => self::body($request, $text, $files),
                'body_html' => '',
            ]);
            foreach ($files as $f) {
                $path = sprintf('mail/%d/drafts/%d/%s', $draft->mailbox_id ?? 0, $draft->id, Str::random(8).'_'.self::safeFilename($f['name']));
                Storage::disk('local')->put($path, $f['bytes']);
                EmailAttachment::create([
                    'email_message_id' => $draft->id,
                    'filename' => mb_substr($f['name'], 0, 255),
                    'mime_type' => $f['mime'] ?: 'application/octet-stream',
                    'size_bytes' => strlen($f['bytes']),
                    'content_id' => null,
                    'file_path' => $path,
                    'disk' => 'local',
                    'is_inline' => false,
                ]);
            }
            $result = $this->sender->sendDraft($draft->id);
        } catch (\Throwable $e) {
            Log::warning('BotDocumentMailer: email copy failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        if (! ($result['success'] ?? false)) {
            try {
                if ($draft !== null) {
                    $this->drafts->delete($draft->fresh() ?? $draft);
                }
            } catch (\Throwable) {
            }

            return ['ok' => false, 'message' => 'Копия на почту не ушла: '.mb_substr((string) ($result['error'] ?? 'ошибка'), 0, 150).'.'];
        }

        return ['ok' => true, 'message' => 'Копия на '.$email.' отправлена.'];
    }

    /** «[MZ2026-01628] Предложение МЗ-373752 от 2026-11-16_08-41-53» — код заявки и имена файлов без расширения. */
    public static function subject(Request $request, array $files): string
    {
        $names = array_map(fn ($f) => (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', (string) $f['name']), $files);

        return mb_substr('['.$request->internal_code.'] '.implode(', ', $names), 0, 255);
    }

    public static function body(Request $request, string $text, array $files): string
    {
        $channel = $request->messengerChat?->channel?->label() ?? 'мессенджер';
        $names = implode(', ', array_map(fn ($f) => (string) $f['name'], $files));

        return "Здравствуйте!\n\n"
            .(trim($text) !== '' ? trim($text)."\n\n" : '')
            .'Во вложении — '.$names.' по заявке '.$request->internal_code.'. Эти же файлы отправили вам в '.$channel.".\n\n"
            .'С уважением,';
    }

    private static function safeFilename(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name), 0, 120) ?: 'file';
    }
}
