<?php

namespace App\Services\Supplier;

use App\Models\SupplierInquiry;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Log;

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
    public function reply(SupplierInquiry $inquiry, User $author, string $plain): array
    {
        $plain = trim($plain);
        if ($plain === '') {
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
