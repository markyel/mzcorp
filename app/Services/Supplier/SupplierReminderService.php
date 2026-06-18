<?php

namespace App\Services\Supplier;

use App\Models\EmailMessage;
use App\Models\Supplier;
use App\Models\SupplierInquiry;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Авто-напоминания поставщикам по открытым запросам расценки без ответа
 * (Фаза 3.5). Напоминание уходит ОТВЕТОМ в том же треде (In-Reply-To на наш
 * исходный RFQ → MIME-builder процитирует оригинал), на языке поставщика.
 * Интервал/лимит — config services.suppliers.reminder.*. См. `suppliers:remind`.
 */
class SupplierReminderService
{
    public function __construct(
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
    ) {
    }

    /**
     * Открытые запросы, которым пора напомнить: с позициями, привязанные к
     * заявке, БЕЗ ответа поставщика, тишина ≥ first_after, лимит не исчерпан,
     * интервал с прошлого напоминания выдержан.
     *
     * @return Collection<int, SupplierInquiry>
     */
    public function dueInquiries(): Collection
    {
        if (! (bool) config('services.suppliers.reminder.enabled', true)) {
            return collect();
        }

        $firstAfter = (int) config('services.suppliers.reminder.first_after_days', 3);
        $interval = (int) config('services.suppliers.reminder.interval_days', 3);
        $max = (int) config('services.suppliers.reminder.max', 2);

        return SupplierInquiry::query()
            ->where('status', 'open')
            ->whereNotNull('related_request_id')
            ->has('items')
            ->whereDoesntHave('messages', fn ($q) => $q->where('direction', 'inbound'))
            ->where('reminders_sent', '<', $max)
            ->where('created_at', '<', now()->subDays($firstAfter))
            ->where(fn ($q) => $q->whereNull('last_reminder_at')->orWhere('last_reminder_at', '<', now()->subDays($interval)))
            ->orderBy('id')
            ->get();
    }

    /**
     * Отправить напоминание поставщику в треде запроса. Возвращает true при
     * успешной отправке. Идемпотентность спейсинга — на вызывающем (dueInquiries
     * / ручной триггер).
     */
    public function remind(SupplierInquiry $inquiry, ?User $author = null): bool
    {
        $author ??= $inquiry->createdBy;
        $request = $inquiry->relatedRequest;
        if ($author === null || $request === null) {
            Log::warning('SupplierReminder: no author/request', ['inquiry_id' => $inquiry->id]);

            return false;
        }
        if (trim((string) $inquiry->supplier_email) === '') {
            return false;
        }

        // Оригинальный RFQ (первое наше исходящее) — якорь треда для цитаты.
        $orig = $inquiry->messages()->where('direction', 'outbound')->orderBy('id')->first();

        $supplier = Supplier::query()->where('email', mb_strtolower(trim((string) $inquiry->supplier_email)))->first();
        $lang = $supplier && $supplier->language === 'en' ? 'en' : 'ru';

        try {
            $draft = $this->drafts->createCompose($request, $author);

            // Тред: In-Reply-To + References на исходный RFQ → MIME-builder
            // подклеит цитату оригинала с позициями.
            if ($orig && $orig->message_id) {
                $refs = array_values(array_unique(array_merge(
                    (array) ($orig->references_header ?? []),
                    [$orig->message_id],
                )));
                $draft->forceFill(['in_reply_to' => $orig->message_id, 'references_header' => $refs])->save();
            }

            $name = trim((string) ($inquiry->supplier_name ?: '')) ?: ($lang === 'en' ? 'colleagues' : 'коллеги');
            $subject = $this->reminderSubject($orig?->subject ?: $inquiry->subject, $lang);
            [$plain, $html] = $this->reminderBody($name, $lang);

            $this->drafts->update($draft, [
                'to_recipients' => [['email' => $inquiry->supplier_email, 'name' => $inquiry->supplier_name ?: '']],
                'subject' => $subject,
                'body_html' => $html,
                'body_plain' => $plain,
            ]);

            $result = $this->sender->sendDraft($draft->id);
            if (! ($result['success'] ?? false)) {
                Log::warning('SupplierReminder: send failed', ['inquiry_id' => $inquiry->id, 'error' => $result['error'] ?? 'unknown']);

                return false;
            }

            // Исходящее напоминание — это переписка с поставщиком, не тред заявки.
            $sent = $result['draft'];
            $sent->forceFill(['supplier_inquiry_id' => $inquiry->id, 'related_request_id' => null])->save();

            $inquiry->forceFill([
                'reminders_sent' => (int) $inquiry->reminders_sent + 1,
                'last_reminder_at' => now(),
            ])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error('SupplierReminder: exception', ['inquiry_id' => $inquiry->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function reminderSubject(?string $base, string $lang): string
    {
        $base = trim((string) $base);
        if ($base === '') {
            $base = $lang === 'en' ? 'Price request' : 'Запрос расценки';
        }
        // Re: один раз.
        if (! preg_match('/^\s*re:/i', $base)) {
            $base = 'Re: ' . $base;
        }

        return mb_substr($base, 0, 255);
    }

    /**
     * @return array{0:string, 1:string}  [plain, html]
     */
    private function reminderBody(string $name, string $lang): array
    {
        if ($lang === 'en') {
            $plain = "Hello {$name},\n\nA gentle reminder about our price request below. "
                . 'We would appreciate your price, availability and lead time for the requested items. Thank you!';
        } else {
            $plain = "Здравствуйте, {$name}!\n\nНапоминаем о нашем запросе расценки ниже. "
                . 'Будем признательны за цену, наличие и срок поставки по запрошенным позициям. Спасибо!';
        }
        $html = '<p style="font-size:14px;margin:0 0 12px;white-space:pre-line">' . e($plain) . '</p>';

        return [$plain, $html];
    }
}
