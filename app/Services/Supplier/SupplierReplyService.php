<?php

namespace App\Services\Supplier;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\SupplierInquiry;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ручной ответ менеджера поставщику в треде запроса расценки. В отличие от
 * авто-напоминания (SupplierReminderService) — произвольный текст менеджера,
 * привязка In-Reply-To к ПОСЛЕДНЕМУ письму треда (обычно ответу поставщика),
 * так что для поставщика это нормальный ответ на его письмо с цитатой.
 * Ящик/черновик — общий SupplierThreadDraftFactory (тот же выбор, что и
 * у напоминаний). После отправки снимаем reply_state — мяч снова у поставщика.
 */
class SupplierReplyService
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
        private readonly SupplierThreadDraftFactory $draftFactory,
    ) {
    }

    /**
     * @return array{success: bool, error?: string}
     */
    /**
     * @param  array<int, array{path:string, name:string, mime:string, size:int}>  $extraFiles
     *         Загруженные фото/файлы (staging на local) — прикрепляются к письму.
     */
    public function reply(SupplierInquiry $inquiry, User $author, string $plain, array $extraFiles = []): array
    {
        $plain = trim($plain);
        if ($plain === '' && $extraFiles === []) {
            return ['success' => false, 'error' => 'Пустой текст ответа.'];
        }
        if (trim((string) $inquiry->supplier_email) === '') {
            return ['success' => false, 'error' => 'У запроса нет e-mail поставщика.'];
        }

        $request = $inquiry->relatedRequest;
        // Исходный RFQ — для выбора ящика в standalone-ветке.
        $orig = $inquiry->messages()->where('direction', 'outbound')->orderBy('id')->first();
        // Якорь треда — ПОСЛЕДНЕЕ письмо (ответ поставщика, если есть): наш ответ
        // ложится In-Reply-To на него, MIME-builder процитирует именно его.
        $anchor = $inquiry->messages()->orderByDesc('sent_at')->orderByDesc('id')->first() ?? $orig;

        try {
            $draft = $request !== null
                ? $this->drafts->createCompose($request, $author)
                : $this->draftFactory->standaloneDraft($inquiry, $orig, $author);
            if ($draft === null) {
                return ['success' => false, 'error' => 'Нет доступного ящика для отправки.'];
            }

            if ($anchor && $anchor->message_id) {
                $refs = array_values(array_unique(array_merge(
                    (array) ($anchor->references_header ?? []),
                    [$anchor->message_id],
                )));
                $draft->forceFill(['in_reply_to' => $anchor->message_id, 'references_header' => $refs])->save();
            }

            $subject = $this->subject($anchor?->subject ?: $inquiry->subject);
            $html = '<p style="font-size:14px;margin:0 0 12px;white-space:pre-line">' . e($plain) . '</p>';

            $this->drafts->update($draft, [
                'to_recipients' => [['email' => $inquiry->supplier_email, 'name' => $inquiry->supplier_name ?: '']],
                'subject' => $subject,
                'body_html' => $html,
                'body_plain' => $plain,
            ]);

            if ($extraFiles !== []) {
                $this->attachFiles($draft->fresh() ?? $draft, $extraFiles);
            }

            $result = $this->sender->sendDraft($draft->id);
            if (! ($result['success'] ?? false)) {
                Log::warning('SupplierReply: send failed', ['inquiry_id' => $inquiry->id, 'error' => $result['error'] ?? 'unknown']);

                return ['success' => false, 'error' => $result['error'] ?? 'Ошибка отправки.'];
            }

            // Исходящее — переписка с поставщиком, не тред заявки.
            $result['draft']->forceFill(['supplier_inquiry_id' => $inquiry->id, 'related_request_id' => null])->save();

            // Ответили — мяч снова у поставщика: снимаем «задал вопрос».
            if ($inquiry->reply_state !== null) {
                $inquiry->forceFill(['reply_state' => null])->save();
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('SupplierReply: exception', ['inquiry_id' => $inquiry->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Внутренняя ошибка: ' . $e->getMessage()];
        }
    }

    /**
     * Скопировать staging-файлы (фото/файлы) в черновик ответа. Non-fatal per file.
     *
     * @param  array<int, array{path:string, name:string, mime:string, size:int}>  $extraFiles
     */
    private function attachFiles(EmailMessage $draft, array $extraFiles): void
    {
        foreach ($extraFiles as $f) {
            $src = (string) ($f['path'] ?? '');
            if ($src === '' || ! Storage::disk('local')->exists($src)) {
                continue;
            }
            try {
                $name = $this->safeName((string) ($f['name'] ?? 'file'));
                $newPath = sprintf('mail/%d/drafts/%d/%s', $draft->mailbox_id ?? 0, $draft->id, Str::random(8) . '_' . $name);
                Storage::disk('local')->put($newPath, Storage::disk('local')->get($src));
                EmailAttachment::create([
                    'email_message_id' => $draft->id,
                    'filename' => mb_substr($name, 0, 255),
                    'mime_type' => (string) ($f['mime'] ?? 'application/octet-stream'),
                    'size_bytes' => (int) ($f['size'] ?? 0),
                    'content_id' => null,
                    'file_path' => $newPath,
                    'disk' => 'local',
                    'is_inline' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('SupplierReply: attach copy failed', ['draft_id' => $draft->id, 'file' => $f['name'] ?? '?', 'error' => $e->getMessage()]);
            }
        }
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name), 0, 120) ?: 'file';
    }

    private function subject(?string $base): string
    {
        $base = trim((string) $base);
        if ($base === '') {
            $base = 'Запрос расценки';
        }
        if (! preg_match('/^\s*re:/i', $base)) {
            $base = 'Re: ' . $base;
        }

        return mb_substr($base, 0, 255);
    }
}
