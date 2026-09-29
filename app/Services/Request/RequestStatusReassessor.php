<?php

namespace App\Services\Request;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use App\Prompts\Request\ReassessRequestStatusPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Переоценщик статуса «застрявшей» заявки. LLM читает всю клиентскую переписку и
 * решает, чей ход. Если ход за клиентом (мы задали вопрос / прислали КП / счёт,
 * а он молчит) — заявка из менеджерского статуса (in_progress/assigned) уходит в
 * waiting-on-client статус, где её штатно подхватывает авто-закрытие по таймауту.
 * Иначе (ход за нами / неясно) — не трогаем.
 */
class RequestStatusReassessor
{
    /** target_status LLM → RequestStatus (только waiting-on-client). */
    private const WAITING_TARGETS = [
        'awaiting_client_clarification' => RequestStatus::AwaitingClientClarification,
        'quoted' => RequestStatus::Quoted,
        'invoiced' => RequestStatus::Invoiced,
    ];

    /** Наш автоответ о получении заявки (шаблон order_received). */
    private const RECEIPT_ACK_RE = '~успешно получено и принято в работу~iu';

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ReassessRequestStatusPrompt $prompt,
        private readonly RequestStateService $stateService,
    ) {
    }

    /**
     * @return array{ball_with:string, target_status:?string, confidence:float, reasoning:string}|null
     */
    public function assess(Request $request): ?array
    {
        if (! config('services.openai.api_key')) {
            return null;
        }
        $transcript = $this->buildTranscript($request);
        if (trim($transcript) === '') {
            return null;
        }

        $model = (string) config(
            'services.openai.reassess_model',
            config('services.openai.outbound_classifier_model', 'gpt-4o-mini'),
        );
        try {
            $response = $this->openai->chat(
                $this->prompt->build($request->status->value, $transcript),
                $model,
                ['temperature' => 0, 'max_tokens' => 260, 'response_format' => ['type' => 'json_object']],
            );
        } catch (\Throwable $e) {
            Log::warning('RequestStatusReassessor: OpenAI failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);

            return null;
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($parsed) || ! isset($parsed['ball_with'])) {
            return null;
        }
        $target = $parsed['target_status'] ?? null;

        return [
            'ball_with' => (string) $parsed['ball_with'],
            'target_status' => ($target !== null && $target !== 'null' && $target !== '') ? (string) $target : null,
            'confidence' => max(0.0, min(1.0, (float) ($parsed['confidence'] ?? 0))),
            'reasoning' => (string) ($parsed['reasoning'] ?? ''),
        ];
    }

    /**
     * Применить переоценку: перевести в waiting-on-client статус, если ход за
     * клиентом и уверенность ≥ порога. Возвращает целевой статус или null.
     */
    public function apply(Request $request, array $decision, ?User $author, float $minConfidence): ?RequestStatus
    {
        if (($decision['ball_with'] ?? null) !== 'client') {
            return null;
        }
        $target = self::WAITING_TARGETS[$decision['target_status'] ?? ''] ?? null;
        if ($target === null || (float) ($decision['confidence'] ?? 0) < $minConfidence) {
            return null;
        }

        // Гард: quoted/invoiced ставим ТОЛЬКО если документ реально есть. Без
        // него target=quoted почти всегда значит, что LLM спутал «получили
        // запрос / обещали вернуться с КП» (ход за НАМИ) с отправленным КП
        // (кейсы M-2026-7840/11039/11069). Раньше понижали до awaiting — ошибочно
        // уводило реальную просрочку менеджера. Теперь ПРОПУСКАЕМ (не трогаем).
        if ($target === RequestStatus::Quoted && ! $this->hasOutboundQuote($request)) {
            return null;
        }
        if ($target === RequestStatus::Invoiced && ! $this->hasInvoice($request)) {
            return null;
        }

        if ($request->status === $target) {
            return null;
        }

        try {
            $this->stateService->transitionTo(
                $request,
                $target,
                $author,
                [
                    'event' => 'llm_reassess',
                    'comment' => 'Переоценка по переписке: ход за клиентом. ' . mb_substr((string) ($decision['reasoning'] ?? ''), 0, 200),
                    'payload' => [
                        'ball_with' => $decision['ball_with'],
                        'confidence' => $decision['confidence'] ?? null,
                        'from' => $request->status->value,
                    ],
                ],
                systemTransition: true,
            );
        } catch (\Throwable $e) {
            Log::warning('RequestStatusReassessor: transition failed', [
                'request_id' => $request->id,
                'target' => $target->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $target;
    }

    /**
     * Кнопка «⚽ Мяч у клиента»: менеджер решил, что ход за клиентом. Модель
     * читает последние письма и говорит, чего ждём; статус выбираем с гардами
     * документов — «КП отправлено» только при распознанном КП, «Счёт
     * выставлен» только при счёте, иначе «Жду клиента». Отслеживание дальше
     * штатное: дедлайн внимания считается от входа в статус (2 раб. дня на
     * ответ, 3 на решение по КП), напоминание клиенту по КП, авто-закрытие.
     * Модель недоступна — всё равно «Жду клиента»: решение менеджера главнее.
     *
     * @return array{status: RequestStatus, what: ?string, warning: ?string, deadline: ?\Illuminate\Support\Carbon}
     */
    public function handToClient(Request $request, User $by): array
    {
        $decision = null;
        $transcript = $this->buildTranscript($request);
        if (trim($transcript) !== '' && config('services.openai.api_key')) {
            try {
                $response = $this->openai->chat(
                    $this->prompt->buildHandover($transcript),
                    (string) config('services.openai.reassess_model', config('services.openai.outbound_classifier_model', 'gpt-4o-mini')),
                    ['temperature' => 0, 'max_tokens' => 300, 'response_format' => ['type' => 'json_object']],
                );
                $decision = json_decode((string) ($response['content'] ?? ''), true);
            } catch (\Throwable $e) {
                Log::warning('RequestStatusReassessor::handToClient: OpenAI failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }
        $decision = is_array($decision) ? $decision : [];
        $waitingFor = (string) ($decision['waiting_for'] ?? 'answer');

        $target = match (true) {
            $waitingFor === 'payment' && $this->hasInvoice($request) => RequestStatus::Invoiced,
            $waitingFor === 'quote_decision' && $this->hasOutboundQuote($request) => RequestStatus::Quoted,
            default => RequestStatus::AwaitingClientClarification,
        };
        $what = trim((string) ($decision['what'] ?? '')) ?: null;
        $warning = ! empty($decision['client_last_unanswered'])
            ? trim('Похоже, последнее письмо клиента осталось без ответа'
                .(! empty($decision['unanswered_quote']) ? ': «'.mb_substr((string) $decision['unanswered_quote'], 0, 100).'»' : '').'.')
            : null;

        $payload = [
            'event' => 'manual_ball_client',
            'comment' => 'Мяч у клиента'.($what ? ': ждём '.$what : '').'.',
            'payload' => [
                'waiting_for' => $waitingFor,
                'what' => $what,
                'client_last_unanswered' => (bool) ($decision['client_last_unanswered'] ?? false),
                'reasoning' => mb_substr((string) ($decision['reasoning'] ?? ''), 0, 300),
                'from' => $request->status->value,
            ],
        ];

        if ($request->status === $target) {
            // Статус уже тот — запускаем отсчёт заново и фиксируем, чего ждём.
            \App\Models\RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $target->value,
                'to_status' => $target->value,
                'by_user_id' => $by->id,
                'event' => $payload['event'],
                'comment' => $payload['comment'],
                'payload' => $payload['payload'],
            ]);
            app(AttentionService::class)->recompute($request->fresh());
        } else {
            $this->stateService->transitionTo($request, $target, $by, $payload);
        }

        return [
            'status' => $target,
            'what' => $what,
            'warning' => $warning,
            'deadline' => $request->fresh()?->attention_required_at,
        ];
    }

    /** Есть ли реально исходящий КП по заявке (для гарда target=quoted). */
    private function hasOutboundQuote(Request $request): bool
    {
        $hasOq = \App\Models\OutboundQuote::query()
            ->where('request_id', $request->id)
            ->where('document_type', 'outbound_quotation_full')
            // КП без позиций (парсер ничего не достал) — не КП; та же логика,
            // что DetectorType::requiresDocumentEvidence.
            ->whereHas('items')
            ->exists();
        if ($hasOq) {
            return true;
        }

        return method_exists($request, 'quotations')
            ? $request->quotations()->whereNotIn('status', ['cancelled'])->exists()
            : false;
    }

    /** Есть ли реально счёт по заявке (для гарда target=invoiced). */
    private function hasInvoice(Request $request): bool
    {
        return method_exists($request, 'invoices')
            ? $request->invoices()->exists()
            : false;
    }

    /** Компактная хронология клиентской переписки для LLM. */
    private function buildTranscript(Request $request): string
    {
        $messages = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->whereNull('supplier_inquiry_id')
            ->where('is_draft', false)
            // ПОСЛЕДНИЕ 24 письма (решает последнее содержательное), в хронологии.
            // Раньше брались первые 24 — в длинной переписке свежих писем модель
            // не видела вовсе.
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get(['id', 'direction', 'subject', 'body_plain', 'body_html', 'sent_at'])
            ->reverse()
            ->values();

        $lines = [];
        foreach ($messages as $m) {
            $who = $m->direction === \App\Enums\MailDirection::Outbound ? 'ОТ НАС' : 'ОТ КЛИЕНТА';
            // Автоответ «письмо получено, номер заявки» — не ответ по сути: без
            // пометки модель считала его нашим ходом и «мяч у клиента» там, где
            // клиенту ещё никто не ответил.
            if ($who === 'ОТ НАС' && preg_match(self::RECEIPT_ACK_RE, (string) ($m->body_plain ?: strip_tags((string) $m->body_html)))) {
                $who = 'ОТ НАС (АВТООТВЕТ о получении заявки — не ответ по сути)';
            }
            $date = $m->sent_at?->format('d.m.Y H:i') ?? '—';
            $text = $this->cleanSnippet((string) ($m->body_plain ?: $m->body_html));
            if ($text === '') {
                $text = '(без текста' . ($m->subject ? ', тема: ' . mb_substr((string) $m->subject, 0, 60) . ')' : ')');
            }
            $lines[] = "[{$date}] {$who}: {$text}";
        }

        return implode("\n", $lines);
    }

    /** Очистить тело: снять HTML/подпись/цитату, обрезать. */
    private function cleanSnippet(string $body): string
    {
        $text = trim(strip_tags($body));
        // Срез подписи «-- » и типовых цитат/пересылок.
        foreach (["\n-- \n", "\n--\n", "\nС уважением", "\nWith best regards", "\n>", "\nОт:", "\nFrom:", "\n----", "\n________"] as $marker) {
            $pos = mb_stripos($text, $marker);
            if ($pos !== false && $pos > 0) {
                $text = mb_substr($text, 0, $pos);
            }
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, 320);
    }
}
