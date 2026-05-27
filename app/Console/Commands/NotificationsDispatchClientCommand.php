<?php

namespace App\Console\Commands;

use App\Enums\ClientNotificationType;
use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\ClarificationBatch;
use App\Models\ClientNotificationSent;
use App\Models\ClientNotificationTemplate;
use App\Models\EmailMessage;
use App\Models\Invoice;
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
            $replyTo = $this->resolveThreadAnchor($request);
            if (! $replyTo) {
                continue;
            }
            $result[] = [
                'request' => $request,
                'scope_key' => '',
                'reply_to' => $replyTo,
                'extra' => [
                    'days_since_quoted' => max(1, (int) $request->updated_at->diffInDays(now())),
                    'quote_amount' => '—', // TODO: достать из последнего AiDecision quote payload
                ],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{request: Request, scope_key: string, reply_to: EmailMessage, extra: array}>
     */
    private function collectInvoiceExpiringSoon(ClientNotificationTemplate $tmpl): array
    {
        $warningDays = $tmpl->warning_days ?: 3;
        $windowEnd = now()->addDays($warningDays);

        $invoices = Invoice::with(['request.emailMessage'])
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
            $replyTo = $this->resolveThreadAnchor($request);
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
        $invoices = Invoice::with(['request.emailMessage'])
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Expired->value])
            ->where('expires_at', '<', now())
            ->get();

        $result = [];
        foreach ($invoices as $inv) {
            $request = $inv->request;
            if (! $request || ! $request->client_email) {
                continue;
            }
            $replyTo = $this->resolveThreadAnchor($request);
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
