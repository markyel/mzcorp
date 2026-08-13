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

    /** Компактная хронология клиентской переписки для LLM. */
    private function buildTranscript(Request $request): string
    {
        $messages = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->whereNull('supplier_inquiry_id')
            ->where('is_draft', false)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get(['id', 'direction', 'subject', 'body_plain', 'body_html', 'sent_at'])
            ->take(24);

        $lines = [];
        foreach ($messages as $m) {
            $who = $m->direction === \App\Enums\MailDirection::Outbound ? 'ОТ НАС' : 'ОТ КЛИЕНТА';
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
