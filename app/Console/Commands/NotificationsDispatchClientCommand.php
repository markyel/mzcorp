<?php

namespace App\Console\Commands;

use App\Enums\ClientNotificationType;
use App\Enums\DetectorType;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Enums\RequestStatus;
use App\Models\CatalogItem;
use App\Models\CatalogPriceChange;
use App\Models\ClarificationBatch;
use App\Models\ClientNotificationSent;
use App\Models\ClientNotificationTemplate;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\OutboundQuote;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Request;
use App\Services\Mail\ClientNotificationService;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
                'scope_key' => 'batch_'.$batch->id,
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

            // Страховка: не шлём напоминание, пока не можем ДОСТОВЕРНО назвать
            // КП (номер + дата отправки) — иначе письмо вида «КП — от — … N дн.
            // назад» с фейковыми днями от updated_at. Сюда попадают Quoted-
            // заявки без сматченного КП (нет system Quotation и нет
            // распарсенного OutboundQuote — парс ещё не прошёл/упал). Когда КП
            // определится (в т.ч. крон quotes:reparse-failed) — напомним
            // корректно в следующий прогон.
            if (empty($quote['number']) || empty($quote['sent_at'])) {
                continue;
            }

            // Якорь: письмо с КП, иначе fallback на последнее входящее клиента.
            $replyTo = ($quote['anchor'] ?? null) ?: $this->resolveThreadAnchor($request);
            if (! $replyTo) {
                continue;
            }

            $result[] = [
                'request' => $request,
                'scope_key' => '',
                'reply_to' => $replyTo,
                'extra' => [
                    'days_since_quoted' => max(1, (int) $quote['sent_at']->diffInDays(now())),
                    'quote_number' => (string) $quote['number'],
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
        $empty = ['number' => null, 'amount' => null, 'date' => null, 'sent_at' => null, 'anchor' => null, 'source' => null, 'source_id' => null];

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
            'source' => 'quotation',
            'source_id' => $quotation->id,
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
            'source' => 'outbound',
            'source_id' => $outbound->id,
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
                'scope_key' => 'invoice_'.$inv->id,
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
                'scope_key' => 'invoice_'.$inv->id,
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

            // Цена, по которой клиента РЕАЛЬНО проквотировали в этом КП
            // (по catalog_item_id) — это и есть «было» для revival; каталожный
            // лист тут ни при чём (клиент видел цену КП, а не каталога).
            $quotedByCatalog = $this->quotedUnitPricesByCatalogItem($quote);
            if ($quotedByCatalog === []) {
                continue; // без построчных цен КП «было» взять неоткуда
            }

            // Падение цены: текущая цена ниже выставленной в КП + реальное
            // изменение каталога после КП (см. collectPriceDrops).
            $dropped = $this->collectPriceDrops($request, $quotedByCatalog, $quoteSentAt, $thresholdPct);
            if ($dropped === []) {
                continue;
            }

            $result[] = [
                'request' => $request,
                'scope_key' => 'quote_'.$quote['number'], // 1 письмо на КП
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
     * Цена, по которой клиента РЕАЛЬНО проквотировали в последнем КП, по
     * catalog_item_id (unit-цена). Это источник истины для «было» в revival:
     * клиент видел цену КП, а не каталожный лист. Берётся из построчных позиций
     * того же КП (Quotation → final_unit_price; OutboundQuote → unit_price).
     *
     * @param  array<string, mixed>  $quote  результат resolveLastSentQuote
     * @return array<int, float> catalog_item_id => unit-цена из КП
     */
    private function quotedUnitPricesByCatalogItem(array $quote): array
    {
        $source = $quote['source'] ?? null;
        $sourceId = $quote['source_id'] ?? null;
        if ($sourceId === null) {
            return [];
        }

        $map = [];
        if ($source === 'quotation') {
            $rows = QuotationItem::query()
                ->where('quotation_id', $sourceId)
                ->whereNotNull('catalog_item_id')
                ->get(['catalog_item_id', 'final_unit_price', 'catalog_unit_price']);
            foreach ($rows as $row) {
                $price = $row->final_unit_price ?? $row->catalog_unit_price;
                if ($price !== null && (float) $price > 0) {
                    $cid = (int) $row->catalog_item_id;
                    // Несколько строк на одну позицию — берём минимальную (что
                    // реально предложили дешевле всего).
                    $map[$cid] = isset($map[$cid]) ? min($map[$cid], (float) $price) : (float) $price;
                }
            }
        } elseif ($source === 'outbound') {
            $rows = DB::table('outbound_quote_items')
                ->where('outbound_quote_id', $sourceId)
                ->whereNotNull('matched_catalog_item_id')
                ->get(['matched_catalog_item_id', 'unit_price']);
            foreach ($rows as $row) {
                if ($row->unit_price !== null && (float) $row->unit_price > 0) {
                    $cid = (int) $row->matched_catalog_item_id;
                    $map[$cid] = isset($map[$cid]) ? min($map[$cid], (float) $row->unit_price) : (float) $row->unit_price;
                }
            }
        }

        return $map;
    }

    /**
     * Позиции заявки, по которым revival оправдан. Условие — ОБА:
     *   1. текущая цена позиции НИЖЕ выставленной клиенту в КП на ≥ порога
     *      (иначе «новая» цена не выгоднее того, что клиент уже видел — ловим
     *      именно этот баг: каталожный лист падал, но был ВЫШЕ цены КП);
     *   2. в каталоге реально было снижение ПОСЛЕ отправки КП ($since)
     *      (CatalogPriceChange) — отсекает «неиспользованную скидку» и держит
     *      объём рассылки под контролем.
     * «было» = цена КП, «стало» = текущая цена каталога (price_min).
     *
     * @param  array<int, float>  $quotedByCatalog  catalog_item_id => unit-цена КП
     * @return array<int, array{name: string, sku: ?string, old: float, new: float, pct: float}>
     */
    private function collectPriceDrops(Request $request, array $quotedByCatalog, mixed $since, float $thresholdPct): array
    {
        $catIds = $request->items
            ->where('is_active', true)
            ->pluck('catalog_item_id')
            ->filter()
            ->map(fn ($c) => (int) $c)
            ->unique()
            ->filter(fn ($cid) => isset($quotedByCatalog[$cid]))
            ->values()
            ->all();
        if ($catIds === []) {
            return [];
        }

        // Условие 2: по каким позициям каталог реально снизился после КП.
        $droppedAfterQuote = CatalogPriceChange::query()
            ->whereIn('catalog_item_id', $catIds)
            ->when($since, fn ($q) => $q->where('changed_at', '>=', $since))
            ->where(fn ($w) => $w
                ->whereColumn('new_price_min', '<', 'old_price_min')
                ->orWhereColumn('new_price', '<', 'old_price'))
            ->pluck('catalog_item_id')
            ->map(fn ($c) => (int) $c)
            ->unique()
            ->flip();

        $catalog = CatalogItem::query()
            ->whereIn('id', $catIds)
            ->get(['id', 'sku', 'name', 'price', 'price_min'])
            ->keyBy('id');

        $byItem = [];
        foreach ($catIds as $cid) {
            $quoted = (float) $quotedByCatalog[$cid];
            $ci = $catalog->get($cid);
            if ($quoted <= 0 || ! $ci) {
                continue;
            }
            // Текущая цена, по которой проквотировали бы сейчас (флор-цена).
            $current = $ci->price_min !== null ? (float) $ci->price_min : (float) $ci->price;
            if ($current <= 0 || $current >= $quoted) {
                continue; // не подешевело относительно КП
            }
            $pct = round(($quoted - $current) / $quoted * 100, 1);
            if ($pct < $thresholdPct) {
                continue;
            }
            // Условие 2: было реальное снижение каталога после КП.
            if (! isset($droppedAfterQuote[$cid])) {
                continue;
            }
            $byItem[$cid] = [
                'name' => (string) ($ci->name ?? $ci->sku ?? '—'),
                'sku' => $ci->sku,
                'old' => $quoted,
                'new' => $current,
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
            $lines[] = '· '.trim($batch->general_question);
        }
        foreach ($questions as $q) {
            $line = '· '.trim((string) $q->question);
            $lines[] = mb_substr($line, 0, 150);
        }

        return implode("\n", $lines);
    }

    private function formatAmount(mixed $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format((float) $amount, 2, ',', ' ').' ₽';
    }
}
