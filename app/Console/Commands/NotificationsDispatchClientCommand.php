<?php

namespace App\Console\Commands;

use App\Enums\ClientNotificationType;
use App\Enums\DetectorType;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Enums\RequestStatus;
use App\Models\CatalogPriceChange;
use App\Models\ClarificationBatch;
use App\Models\ClientNotificationSent;
use App\Models\ClientNotificationTemplate;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\OutboundQuote;
use App\Models\Quotation;
use App\Models\Request;
use App\Services\Mail\ClientNotificationService;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cron: рассылка автоматических уведомлений клиентам по 4 cron-типам.
 *
 * OrderReceived — НЕ через cron (синхронный hook в AssignmentService).
 *
 * Threshold (через сколько слать reminder) берётся из:
 *   1. ClientNotificationTemplate.threshold_hours / warning_days (override);
 *   2. Если null — fallback на соответствующий attention deadline:
 *       - clarification_reminder → attention.awaiting_clarification_days
 *       - quote_followup_reminder → attention.quoted_first_followup_days
 *       - invoice_expiring_soon → template.warning_days (по умолчанию 3)
 *       - invoice_expired → 0 (сразу после expires_at)
 *
 * Идемпотентность — через uniq(request_id, type, scope_key) в
 * client_notifications_sent. dispatch'ам шлёт только ENABLED-типы.
 *
 * Usage:
 *   php artisan notifications:dispatch-client
 *   php artisan notifications:dispatch-client --type=clarification_reminder
 *   php artisan notifications:dispatch-client --dry-run
 */
class NotificationsDispatchClientCommand extends Command
{
    protected $signature = 'notifications:dispatch-client
        {--type= : Один конкретный тип (clarification_reminder | quote_followup_reminder | invoice_expiring_soon | invoice_expired)}
        {--dry-run : Только показать кандидатов, не отправлять}';

    protected $description = 'Cron-рассылка автоматических уведомлений клиенту (4 типа).';

    public function handle(
        ClientNotificationService $notifier,
        SettingsService $settings,
    ): int {
        $onlyType = $this->option('type');
        $dryRun = (bool) $this->option('dry-run');

        $stats = [
            ClientNotificationType::ClarificationReminder->value => 0,
            ClientNotificationType::QuoteFollowupReminder->value => 0,
            ClientNotificationType::InvoiceExpiringSoon->value => 0,
            ClientNotificationType::InvoiceExpired->value => 0,
            ClientNotificationType::RevivalOffer->value => 0,
        ];

        $types = ClientNotificationType::cronTriggerCases();
        if ($onlyType) {
            $types = array_filter($types, fn ($t) => $t->value === $onlyType);
        }

        foreach ($types as $type) {
            $template = ClientNotificationTemplate::forType($type);
            if (! $template->is_enabled) {
                $this->line("· {$type->value}: disabled, skip");
                continue;
            }

            $candidates = match ($type) {
                ClientNotificationType::ClarificationReminder => $this->collectClarificationReminders($template, $settings),
                ClientNotificationType::QuoteFollowupReminder => $this->collectQuoteFollowupReminders($template, $settings),
                ClientNotificationType::InvoiceExpiringSoon => $this->collectInvoiceExpiringSoon($template),
                ClientNotificationType::InvoiceExpired => $this->collectInvoiceExpired($template),
                ClientNotificationType::RevivalOffer => $this->collectRevivalOffers($template, $settings),
                default => [],
            };

            $this->line(sprintf('· %s: %d candidate(s)', $type->value, count($candidates)));

            foreach ($candidates as $c) {
                /** @var Request $request */
                $request = $c['request'];
                $scopeKey = $c['scope_key'];
                $replyTo = $c['reply_to'];
                $extra = $c['extra'] ?? [];

                $exists = ClientNotificationSent::where('request_id', $request->id)
                    ->where('type', $type->value)
                    ->where('scope_key', $scopeKey)
                    ->exists();
                if ($exists) {
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '   DRY: %s scope=%s → %s',
                        $request->internal_code,
                        $scopeKey ?: '-',
                        $request->client_email ?: '?'
                    ));
                    continue;
                }

                try {
                    $ok = $notifier->dispatch(
                        request: $request,
                        type: $type,
                        scopeKey: $scopeKey,
                        replyTo: $replyTo,
                        extra: $extra,
                    );
                    if ($ok) {
                        $stats[$type->value]++;
                        $this->line(sprintf('   SENT: %s → %s', $request->internal_code, $request->client_email));
                    }
                } catch (\Throwable $e) {
                    Log::error('notifications:dispatch-client: failed', [
                        'request_id' => $request->id,
                        'type' => $type->value,
                        'error' => $e->getMessage(),
                    ]);
                    $this->error(sprintf('   ERR : %s — %s', $request->internal_code, $e->getMessage()));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('Готово. Отправлено: %s', json_encode($stats, JSON_UNESCAPED_UNICODE)));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{request: Request, scope_key: string, reply_to: EmailMessage, extra: array}>
     */
    private function collectClarificationReminders(ClientNotificationTemplate $tmpl, SettingsService $settings): array
    {
        $thresholdHours = $tmpl->threshold_hours
            ?? ((int) $settings->get('attention.awaiting_clarification_days', 2) * 24);
        $cutoff = now()->subHours($thresholdHours);

        $batches = ClarificationBatch::with(['request.emailMessage'])
            ->where('status', 'sent')
            ->whereNull('answered_at')
            ->where('sent_at', '<', $cutoff)
            ->get();

        $result = [];
        foreach ($batches as $batch) {
            $request = $batch->request;
            if (! $request || ! $request->client_email) {
                continue;
            }
            if (in_array($request->status?->value, ['paused', 'closed_won', 'closed_lost'], true)) {
                continue;
            }
            $replyTo = $this->resolveThreadAnchor($request);
            if (! $replyTo) {
                continue;
            }
            $result[] = [
                'request' => $request,
                'scope_key' => 'batch_' . $batch->id,
                'reply_to' => $replyTo,
                'extra' => [
                    'days_since_sent' => max(1, (int) $batch->sent_at->diffInDays(now())),
                    'questions_summary' => $this->buildQuestionsSummary($batch),
                ],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{request: Request, scope_key: string, reply_to: EmailMessage, extra: array}>
     */
    private function collectQuoteFollowupReminders(ClientNotificationTemplate $tmpl, SettingsService $settings): array
    {
        $thresholdHours = $tmpl->threshold_hours
            ?? ((int) $settings->get('attention.quoted_first_followup_days', 3) * 24);
        $cutoff = now()->subHours($thresholdHours);

        // Заявки в Quoted без перехода в AwaitingInvoice/Invoiced/Paid + давно.
        $candidates = Request::with(['emailMessage'])
            ->where('status', RequestStatus::Quoted->value)
            ->where('updated_at', '<', $cutoff)
            ->get();

        $result = [];
        foreach ($candidates as $request) {
            if (! $request->client_email) {
                continue;
            }

            // Реальное отправленное КП заявки (UI-Quotation ИЛИ распарсенное
            // внешнее OutboundQuote). Берём его номер/сумму/дату И его
            // исходящее письмо как якорь треда — напоминание садится в ветку
            // самого КП, клиент видит, о каком номере речь.
            $quote = $this->resolveLastSentQuote($request);

            // Якорь: письмо с КП, иначе fallback на последнее входящее клиента.
            $replyTo = ($quote['anchor'] ?? null) ?: $this->resolveThreadAnchor($request);
            if (! $replyTo) {
                continue;
            }

            // days_since считаем от даты отправки КП (если известна), иначе от
            // updated_at заявки — прежнее поведение.
            $quotedAt = $quote['sent_at'] ?? $request->updated_at;

            $result[] = [
                'request' => $request,
                'scope_key' => '',
                'reply_to' => $replyTo,
                'extra' => [
                    'days_since_quoted' => max(1, (int) $quotedAt->diffInDays(now())),
                    'quote_number' => $quote['number'] ?? '—',
                    'quote_amount' => $quote['amount'] !== null ? $this->formatAmount($quote['amount']) : '—',
                    'quote_date' => $quote['date'] ?? '—',
                ],
            ];
        }

        return $result;
    }

    /**
     * Найти последнее реально отправленное клиенту КП заявки.
     *
     * Два источника (Foundation §«2 варианта КП»):
     *   1. Quotation (status=Sent) — КП сформировано в нашем UI и отправлено
     *      через mzcorp. Авторитетный номер = internal_code, сумма = total,
     *      якорь = sent_email_message_id.
     *   2. OutboundQuote (document_type=outbound_quotation_full|partial) —
     *      внешнее КП, отправленное менеджером по почте и распознанное
     *      детектором. Номер = document_number, сумма = total_amount,
     *      якорь = email_message_id.
     *
     * UI-КП, отправленное через mzcorp, может попасть в ОБА (исходящее письмо
     * парсится детектором). Поэтому при равенстве по времени приоритет у
     * Quotation (там номер авторитетный). Иначе — у того, что отправлено
     * позже.
     *
     * @return array{number: ?string, amount: ?float, date: ?string, sent_at: ?Carbon, anchor: ?EmailMessage}
     */
    private function resolveLastSentQuote(Request $request): array
    {
        $empty = ['number' => null, 'amount' => null, 'date' => null, 'sent_at' => null, 'anchor' => null];

        $quotation = Quotation::with('sentEmailMessage')
            ->where('request_id', $request->id)
            ->where('status', QuotationStatus::Sent->value)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->first();

        $outbound = OutboundQuote::with('emailMessage')
            ->where('request_id', $request->id)
            ->whereIn('document_type', [
                DetectorType::OutboundQuotationFull->value,
                DetectorType::OutboundQuotationPartial->value,
            ])
            ->whereIn('status', [OutboundQuote::STATUS_PARSED, OutboundQuote::STATUS_MATCHED])
            ->orderByDesc('id')
            ->first();

        $fromQuotation = $quotation ? [
            'number' => (string) $quotation->internal_code,
            'amount' => $quotation->total !== null ? (float) $quotation->total : null,
            'date' => optional($quotation->sent_at)->format('d.m.Y'),
            'sent_at' => $quotation->sent_at,
            'anchor' => $quotation->sentEmailMessage,
        ] : null;

        $outboundSentAt = $outbound
            ? ($outbound->emailMessage?->sent_at
                ?? ($outbound->document_date ? Carbon::parse((string) $outbound->document_date) : null))
            : null;
        $fromOutbound = $outbound ? [
            'number' => $outbound->document_number !== null && $outbound->document_number !== ''
                ? (string) $outbound->document_number
                : null,
            'amount' => $outbound->total_amount !== null ? (float) $outbound->total_amount : null,
            'date' => $outbound->document_date?->format('d.m.Y'),
            'sent_at' => $outboundSentAt,
            'anchor' => $outbound->emailMessage,
        ] : null;

        if (! $fromQuotation && ! $fromOutbound) {
            return $empty;
        }
        if ($fromQuotation && ! $fromOutbound) {
            return $fromQuotation;
        }
        if ($fromOutbound && ! $fromQuotation) {
            return $fromOutbound;
        }

        // Оба есть — берём отправленное позже; при равенстве/неизвестном
        // времени приоритет Quotation (авторитетный номер).
        $qAt = $fromQuotation['sent_at'];
        $oAt = $fromOutbound['sent_at'];
        if ($qAt && $oAt) {
            return $oAt->gt($qAt) ? $fromOutbound : $fromQuotation;
        }

        return $fromQuotation;
    }

    /**
     * @return array<int, array{request: Request, scope_key: string, reply_to: EmailMessage, extra: array}>
     */
    private function collectInvoiceExpiringSoon(ClientNotificationTemplate $tmpl): array
    {
        $warningDays = $tmpl->warning_days ?: 3;
        $windowEnd = now()->addDays($warningDays);

        $invoices = Invoice::with(['request.emailMessage', 'emailMessage'])
            ->where('status', InvoiceStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $windowEnd)
            ->get();

        $result = [];
        foreach ($invoices as $inv) {
            $request = $inv->request;
            if (! $request || ! $request->client_email) {
                continue;
            }
            // Якорь — исходящее письмо самого счёта (Invoice.email_message_id),
            // чтобы напоминание село в ветку счёта; fallback на последнее
            // входящее клиента.
            $replyTo = $inv->emailMessage ?: $this->resolveThreadAnchor($request);
            if (! $replyTo) {
                continue;
            }
            $daysUntil = max(0, (int) now()->diffInDays($inv->expires_at, false));
            $result[] = [
                'request' => $request,
                'scope_key' => 'invoice_' . $inv->id,
                'reply_to' => $replyTo,
                'extra' => [
                    'invoice_number' => (string) $inv->invoice_number,
                    'invoice_amount' => $this->formatAmount($inv->amount_snapshot),
                    'invoice_expires_at' => $inv->expires_at?->format('d.m.Y'),
                    'days_until_expiry' => $daysUntil,
                ],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{request: Request, scope_key: string, reply_to: EmailMessage, extra: array}>
     */
    private function collectInvoiceExpired(ClientNotificationTemplate $tmpl): array
    {
        $invoices = Invoice::with(['request.emailMessage', 'emailMessage'])
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Expired->value])
            ->where('expires_at', '<', now())
            ->get();

        $result = [];
        foreach ($invoices as $inv) {
            $request = $inv->request;
            if (! $request || ! $request->client_email) {
                continue;
            }
            // Якорь — исходящее письмо самого счёта; fallback на последнее
            // входящее клиента.
            $replyTo = $inv->emailMessage ?: $this->resolveThreadAnchor($request);
            if (! $replyTo) {
                continue;
            }
            $daysSince = max(1, (int) $inv->expires_at->diffInDays(now()));
            $result[] = [
                'request' => $request,
                'scope_key' => 'invoice_' . $inv->id,
                'reply_to' => $replyTo,
                'extra' => [
                    'invoice_number' => (string) $inv->invoice_number,
                    'invoice_amount' => $this->formatAmount($inv->amount_snapshot),
                    'invoice_expired_at' => $inv->expires_at?->format('d.m.Y'),
                    'days_since_expiry' => $daysSince,
                ],
            ];
        }

        return $result;
    }

    /**
     * «Оживляющие» письма: проигранные за период заявки с КП, по позициям
     * которых цена в каталоге упала сильнее порога уже ПОСЛЕ отправки КП.
     * Одно письмо на КП (scope_key = номер КП).
     *
     * @return array<int, array{request: Request, scope_key: string, reply_to: EmailMessage, extra: array}>
     */
    private function collectRevivalOffers(ClientNotificationTemplate $tmpl, SettingsService $settings): array
    {
        $periodDays = (int) $settings->get('notifications.revival.period_days', (int) config('services.notifications.revival.period_days', 14));
        $thresholdPct = (float) $settings->get('notifications.revival.drop_threshold_pct', (float) config('services.notifications.revival.drop_threshold_pct', 15));
        $replyKeyword = (string) $settings->get('notifications.revival.reply_keyword', (string) config('services.notifications.revival.reply_keyword', 'прислать новое КП'));

        $cutoff = now()->subDays(max(1, $periodDays));

        $requests = Request::with(['emailMessage', 'items'])
            ->where('status', RequestStatus::ClosedLost->value)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $cutoff)
            ->whereNotNull('client_email')
            ->get();

        $result = [];
        foreach ($requests as $request) {
            if (! $request->client_email) {
                continue;
            }

            // Реально отправленное КП + якорь треда (письмо с КП).
            $quote = $this->resolveLastSentQuote($request);
            if (($quote['number'] ?? null) === null || ($quote['anchor'] ?? null) === null) {
                continue;
            }
            $quoteSentAt = $quote['sent_at'] ?? $request->closed_at;

            // Падение цены по позициям заявки ПОСЛЕ отправки КП.
            $dropped = $this->collectPriceDrops($request, $quoteSentAt, $thresholdPct);
            if ($dropped === []) {
                continue;
            }

            $result[] = [
                'request' => $request,
                'scope_key' => 'quote_' . $quote['number'], // 1 письмо на КП
                'reply_to' => $quote['anchor'],
                'extra' => [
                    'quote_number' => $quote['number'],
                    'quote_date' => $quote['date'] ?? '—',
                    'dropped_summary' => $this->buildDroppedSummary($dropped),
                    'reply_keyword' => $replyKeyword,
                ],
            ];
        }

        return $result;
    }

    /**
     * Позиции заявки, по которым цена в каталоге упала сильнее $thresholdPct
     * уже ПОСЛЕ $since (одно — самое свежее — изменение на позицию).
     *
     * @return array<int, array{name: string, sku: ?string, old: float, new: float, pct: float}>
     */
    private function collectPriceDrops(Request $request, mixed $since, float $thresholdPct): array
    {
        $catIds = $request->items
            ->where('is_active', true)
            ->pluck('catalog_item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($catIds === []) {
            return [];
        }

        $changes = CatalogPriceChange::query()
            ->whereIn('catalog_item_id', $catIds)
            ->whereNotNull('old_price')
            ->whereNotNull('new_price')
            ->whereColumn('new_price', '<', 'old_price')
            ->when($since, fn ($q) => $q->where('changed_at', '>=', $since))
            ->with('catalogItem:id,sku,name')
            ->orderByDesc('id')
            ->get();

        $byItem = [];
        foreach ($changes as $ch) {
            $cid = (int) $ch->catalog_item_id;
            if (isset($byItem[$cid])) {
                continue; // уже взяли самое свежее изменение (orderByDesc id)
            }
            $old = (float) $ch->old_price;
            $new = (float) $ch->new_price;
            if ($old <= 0) {
                continue;
            }
            $pct = round(($old - $new) / $old * 100, 1);
            if ($pct < $thresholdPct) {
                continue;
            }
            $byItem[$cid] = [
                'name' => (string) ($ch->catalogItem?->name ?? $ch->sku ?? '—'),
                'sku' => $ch->sku,
                'old' => $old,
                'new' => $new,
                'pct' => $pct,
            ];
        }

        $rows = array_values($byItem);
        usort($rows, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        return $rows;
    }

    /**
     * @param  array<int, array{name: string, sku: ?string, old: float, new: float, pct: float}>  $dropped
     */
    private function buildDroppedSummary(array $dropped): string
    {
        $lines = [];
        foreach (array_slice($dropped, 0, 5) as $d) {
            $lines[] = sprintf(
                '· %s — было %s ₽, стало %s ₽ (−%s%%)',
                mb_substr((string) $d['name'], 0, 80),
                number_format($d['old'], 0, ',', ' '),
                number_format($d['new'], 0, ',', ' '),
                rtrim(rtrim(number_format($d['pct'], 1, '.', ''), '0'), '.'),
            );
        }
        if (count($dropped) > 5) {
            $lines[] = '· … и другие позиции';
        }

        return implode("\n", $lines);
    }

    /**
     * Найти последнее inbound-письмо клиента в треде заявки — нам нужен
     * In-Reply-To anchor. Если такого нет (странно) — берём origin.
     */
    private function resolveThreadAnchor(Request $request): ?EmailMessage
    {
        $lastInbound = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->where('direction', 'inbound')
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->orderByDesc('id')
            ->first();

        return $lastInbound ?? $request->emailMessage;
    }

    private function buildQuestionsSummary(ClarificationBatch $batch): string
    {
        $questions = $batch->questions()->limit(5)->get();
        if ($questions->isEmpty()) {
            return $batch->general_question ?: '—';
        }
        $lines = [];
        if ($batch->general_question) {
            $lines[] = '· ' . trim($batch->general_question);
        }
        foreach ($questions as $q) {
            $line = '· ' . trim((string) $q->question);
            $lines[] = mb_substr($line, 0, 150);
        }

        return implode("\n", $lines);
    }

    private function formatAmount(mixed $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format((float) $amount, 2, ',', ' ') . ' ₽';
    }
}
