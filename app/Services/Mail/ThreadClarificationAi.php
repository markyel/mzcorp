<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Prompts\Mail\ThreadClarificationPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 5-й уровень thread linking — AI multi-choice.
 *
 * Когда у клиента 2+ открытых заявок и `InboundReplyLinker` уровень 4
 * наконец нашёл reply, нужно решить к какой именно заявке его прицепить.
 * Эвристика «самая свежая» (что было до этого) грубая — клиент мог
 * отвечать на старую тему параллельно с новой. AI смотрит на тело письма
 * и выбирает наиболее подходящую открытую заявку (или null = новая тема).
 *
 * Cost: ~$0.001-0.005 на вызов (gpt-4o-mini, ~1000 токенов prompt'а).
 * Вызывается ТОЛЬКО при reply + 2+ открытых заявок — редкий путь.
 *
 * При сбое AI (timeout / парс-ошибка / null) — fallback на «самую свежую»
 * (текущая логика уровня 4), pipeline не валится.
 */
class ThreadClarificationAi
{
    /** Cap кандидатов: при 5+ открытых заявках берём 5 свежайших (промпт не раздуваем). */
    private const MAX_CANDIDATES = 5;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly EmailTextCleanerService $cleaner = new EmailTextCleanerService(),
    ) {
    }

    /**
     * @param  Collection<int, Request>  $openRequests  Все открытые Request клиента (2+).
     * @return Request|null  Выбранная заявка или null = новая тема.
     */
    public function chooseRequest(EmailMessage $message, Collection $openRequests): ?Request
    {
        if ($openRequests->count() < 2) {
            // Защита: вызываем только когда выбор реально нужен.
            return $openRequests->first();
        }

        $candidates = $openRequests
            ->sortByDesc('created_at')
            ->take(self::MAX_CANDIDATES);

        $payload = $candidates->map(function (Request $r) {
            $items = $r->items()->limit(8)->get(['parsed_name', 'parsed_brand', 'parsed_qty', 'parsed_unit']);
            $itemsSummary = $items->map(function ($it) {
                $parts = [trim((string) $it->parsed_name)];
                if ($it->parsed_brand) {
                    $parts[] = $it->parsed_brand;
                }
                if ($it->parsed_qty) {
                    $parts[] = rtrim(rtrim((string) $it->parsed_qty, '0'), '.') . ' ' . ($it->parsed_unit ?: 'шт.');
                }
                return implode(', ', array_filter($parts));
            })->filter()->take(5)->implode('; ');

            return [
                'id' => $r->id,
                'internal_code' => $r->internal_code,
                'subject' => (string) $r->subject,
                'items_summary' => mb_substr($itemsSummary, 0, 400),
                'created_at' => $r->created_at?->format('Y-m-d H:i') ?? '?',
            ];
        })->values()->all();

        // Body для промпта: используем cleaned plain (или html→text fallback —
        // ту же логику что и parser для согласованности).
        $rawBody = (string) ($message->body_plain ?? '');
        if ($this->cleaner->bodyPlainLooksBroken($rawBody) && trim((string) $message->body_html) !== '') {
            $rawBody = $this->cleaner->htmlToText((string) $message->body_html);
        }
        $cleanedBody = $this->cleaner->cleanInboundReferenceText($rawBody);

        $userMsg = ThreadClarificationPrompt::userMessage(
            (string) $message->from_email,
            $message->subject,
            $cleanedBody,
            $payload,
        );

        try {
            $result = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => ThreadClarificationPrompt::systemMessage()],
                    ['role' => 'user', 'content' => $userMsg],
                ],
                config('services.openai.clarification_model', 'gpt-4o-mini'),
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0],
            );
        } catch (\Throwable $e) {
            Log::warning('ThreadClarificationAi: chat call failed (non-fatal, fallback to latest)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return $candidates->first();
        }

        $parsed = json_decode((string) ($result['content'] ?? ''), true);
        if (! is_array($parsed) || ! array_key_exists('matched_request_id', $parsed)) {
            Log::warning('ThreadClarificationAi: invalid JSON from AI (fallback to latest)', [
                'email_message_id' => $message->id,
                'content' => mb_substr((string) ($result['content'] ?? ''), 0, 200),
            ]);

            return $candidates->first();
        }

        $matchedId = $parsed['matched_request_id'];
        if ($matchedId === null) {
            Log::info('ThreadClarificationAi: AI says — это новая тема', [
                'email_message_id' => $message->id,
                'reasoning' => mb_substr((string) ($parsed['reasoning'] ?? ''), 0, 300),
            ]);

            return null;
        }

        $matched = $openRequests->firstWhere('id', (int) $matchedId);
        if (! $matched) {
            // AI выдал id которого нет в нашем списке — fallback.
            Log::warning('ThreadClarificationAi: AI выбрал несуществующий id (fallback to latest)', [
                'email_message_id' => $message->id,
                'ai_matched_id' => $matchedId,
                'available_ids' => $openRequests->pluck('id')->all(),
            ]);

            return $candidates->first();
        }

        Log::info('ThreadClarificationAi: matched by AI', [
            'email_message_id' => $message->id,
            'request_id' => $matched->id,
            'internal_code' => $matched->internal_code,
            'reasoning' => mb_substr((string) ($parsed['reasoning'] ?? ''), 0, 300),
        ]);

        return $matched;
    }
}
