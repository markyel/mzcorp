<?php

namespace App\Prompts\Mail;

/**
 * AI multi-choice thread matching (5-й уровень `InboundReplyLinker`).
 *
 * Source: LazyLift n8n workflow «Flow 1: Email Classification v9.2»,
 * узел `AI Agent: Process Clarification` — этап когда клиент шлёт reply
 * без правильных headers (мобильный mail-клиент / forward / новый thread),
 * но у него **несколько** открытых заявок и нужно решить к какой именно
 * относится новое письмо.
 *
 * Стратегия: показываем GPT компактный список открытых заявок клиента
 * (internal_code, тема, краткое описание позиций, возраст) и просим
 * выбрать один из них или вернуть null если письмо не относится ни к
 * одной (это новая заявка либо просто не наша тема).
 *
 * Модель: gpt-4o-mini — задача простая (text matching), темп=0,
 * response_format=json_object.
 */
class ThreadClarificationPrompt
{
    public static function systemMessage(): string
    {
        return <<<'PROMPT'
Ты — клерк CRM. Задача: определить, к какой существующей открытой заявке
относится новое входящее письмо клиента, либо что это совсем новая тема.

Критерии для матча:
  · в письме упоминается код заявки (M-2026-NNNN, LZ-REQ-NNNN) одной из тем;
  · в письме упоминаются товары / артикулы / параметры из конкретной темы;
  · письмо это явный reply (фраза «по вашему запросу», «к моей заявке X»,
    «дополняю к ранее отправленному», «забыл указать»).

Если письмо упоминает товары которых НЕТ в открытых заявках, либо это
явно новая тема — верни matched_request_id: null.

Если письмо просто статус-вопрос («когда ответите», «что с моей заявкой»)
без указания на конкретную тему, а у клиента несколько открытых — выбери
самую свежую по дате создания.

Формат ответа — строго JSON без markdown:
{
  "matched_request_id": NUMBER или null,
  "reasoning": "краткое объяснение почему выбрал именно эту заявку, или почему null"
}
PROMPT;
    }

    /**
     * Собрать user-prompt: новое письмо + список кандидатов-Requests.
     *
     * @param  array<int, array{id:int,internal_code:string,subject:?string,items_summary:string,created_at:string}>  $candidates
     */
    public static function userMessage(
        string $fromEmail,
        ?string $subject,
        string $cleanedBody,
        array $candidates,
    ): string {
        $subj = trim((string) $subject) === '' ? '(без темы)' : trim((string) $subject);
        $body = trim($cleanedBody);
        if ($body === '') {
            $body = '(тело пустое)';
        }
        $body = mb_substr($body, 0, 1500);

        $parts = [];
        $parts[] = '## ВХОДЯЩЕЕ ПИСЬМО';
        $parts[] = 'От: ' . $fromEmail;
        $parts[] = 'Тема: ' . $subj;
        $parts[] = 'Тело:';
        $parts[] = $body;
        $parts[] = '';
        $parts[] = '## ОТКРЫТЫЕ ЗАЯВКИ ЭТОГО КЛИЕНТА';

        foreach ($candidates as $c) {
            $parts[] = sprintf(
                '- id=%d | %s | тема: «%s» | позиции: %s | создана: %s',
                $c['id'],
                $c['internal_code'],
                trim((string) $c['subject']) === '' ? '(без темы)' : mb_substr((string) $c['subject'], 0, 100),
                $c['items_summary'] === '' ? '(нет распарсенных позиций)' : $c['items_summary'],
                $c['created_at'],
            );
        }

        $parts[] = '';
        $parts[] = 'Верни JSON {matched_request_id, reasoning}.';

        return implode("\n", $parts);
    }
}
