<?php

namespace App\Prompts\Request;

use App\Models\Request as RequestModel;
use App\Models\EmailMessage;

/**
 * Phase 2.1 — промпт LLM-проверки гипотезы наследования.
 *
 * Контекст: новая Request создана из входящего письма клиента. Linker
 * (Levels 1-4) выявил closed_lost кандидата того же клиента — есть
 * подозрение, что клиент пишет именно по той заявке (просит обновить
 * КП / счёт / уточняет вопрос месячной давности). Реанимировать
 * автоматически нельзя (false positives), но и игнорировать связь
 * нельзя (теряем контекст).
 *
 * Этот промпт — финальный валидатор: возвращает true/false с confidence,
 * чтобы решить — линковать новую заявку как наследника от архивной или
 * оставить отдельной.
 *
 * Модель: gpt-4o-mini (дёшево, быстро, задача бинарная).
 */
class CheckInheritanceCandidatePrompt
{
    private const MAX_BODY_CHARS = 4000;
    private const MAX_ITEMS_TO_LIST = 20;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(
        EmailMessage $inboundMessage,
        RequestModel $newRequest,
        RequestModel $candidateArchive,
    ): array {
        $newRequest->loadMissing('items');
        $candidateArchive->loadMissing('items');

        $body = (string) ($inboundMessage->body_plain ?: strip_tags((string) $inboundMessage->body_html));
        $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);

        $newItems = $this->formatItems($newRequest);
        $archiveItems = $this->formatItems($candidateArchive);

        $closedAt = $candidateArchive->closed_at?->toDateString() ?? '—';
        $closedReason = (string) ($candidateArchive->closed_lost_reason ?? '—');
        $closedComment = mb_substr((string) ($candidateArchive->closed_lost_comment ?? ''), 0, 200);

        $userPrompt = "## АРХИВНАЯ ЗАЯВКА (кандидат для наследования)\n"
            . "Код: {$candidateArchive->internal_code}\n"
            . "Тема: " . ($candidateArchive->subject ?: '(без темы)') . "\n"
            . "Закрыта: {$closedAt} (причина: {$closedReason})\n"
            . ($closedComment !== '' ? "Комментарий закрытия: {$closedComment}\n" : '')
            . "Позиции архивной:\n{$archiveItems}\n"
            . "\n"
            . "## НОВОЕ ВХОДЯЩЕЕ ПИСЬМО КЛИЕНТА\n"
            . "Тема: " . ($inboundMessage->subject ?: '(без темы)') . "\n"
            . "От: " . ($inboundMessage->from_email ?? '') . "\n"
            . "Текст:\n{$body}\n"
            . "\n"
            . "## ПОЗИЦИИ НОВОЙ ЗАЯВКИ (распарсенные из письма/вложений)\n"
            . "{$newItems}\n"
            . "\n"
            . "## ВОПРОС\n"
            . "Новое письмо клиента — это **продолжение** архивной заявки "
            . "({$candidateArchive->internal_code})? То есть клиент возвращается "
            . "именно к тому же запросу, который мы уже обсуждали (просит обновить "
            . "КП/счёт/уточнить тот же товар), а не присылает новый запрос на другое?";

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    private function formatItems(RequestModel $request): string
    {
        $items = $request->items
            ->where('is_active', true)
            ->take(self::MAX_ITEMS_TO_LIST);

        if ($items->isEmpty()) {
            return '(позиций нет)';
        }

        $lines = [];
        foreach ($items as $i => $item) {
            $brand = $item->parsed_brand ? "{$item->parsed_brand} " : '';
            $art = $item->parsed_article ? "[{$item->parsed_article}] " : '';
            $name = $item->parsed_name ?: '(без названия)';
            $qty = $item->parsed_qty ? " — {$item->parsed_qty} " . ($item->parsed_unit ?: 'шт') : '';
            $lines[] = ($i + 1) . '. ' . $brand . $art . $name . $qty;
        }

        $total = $request->items->where('is_active', true)->count();
        if ($total > self::MAX_ITEMS_TO_LIST) {
            $lines[] = '… и ещё ' . ($total - self::MAX_ITEMS_TO_LIST) . ' позиций';
        }

        return implode("\n", $lines);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты — валидатор связи между двумя заявками. Задача: решить, является ли
новое входящее письмо клиента **продолжением** конкретной архивной
закрытой заявки, или это **новый отдельный запрос** того же клиента
(который случайно похож на старую заявку или матчится по адресу).

Правила оценки:

1. **Сильные сигналы продолжения (→ is_continuation=true):**
   - В новом письме упоминаются те же позиции (артикул / название / бренд),
     что и в архивной.
   - В новом письме явный возврат к диалогу: «обновите КП на ту же позицию»,
     «уточните цену по нашему запросу», «нужны те же товары».
   - Цитата из нашего исходящего по той же позиции / тому же КП.
   - Тема нового письма явно про тот же товар (модель / артикул совпадают).

2. **Сильные сигналы НОВОГО запроса (→ is_continuation=false):**
   - Новое письмо про другие товары (другие артикулы / другие категории).
   - Тема новая, не связанная с архивной.
   - Новые позиции совершенно не пересекаются с архивными.
   - В письме нет упоминаний / цитат архивного контекста.

3. **Слабые сигналы** (один сам по себе не решает):
   - Совпадение бренда без совпадения артикула/названия.
   - Цитата нашего ответа без явной связи позиций.

4. **Эмпирика confidence:**
   - 0.95+ — явное продолжение (≥1 совпавшая позиция + явный текст о возврате).
   - 0.7–0.95 — вероятное продолжение (часть позиций совпадает ИЛИ явная
     отсылка к архивной заявке).
   - <0.7 — неуверен, скорее новый запрос. Возвращай is_continuation=false.

Если позиции новой заявки не пересекаются с архивной по смыслу — это
ДВА разных запроса одного клиента, не наследование. Лучше создать
новую отдельную заявку без связи, чем ошибочно связать.

ФОРМАТ ОТВЕТА — строго JSON без markdown:

{
  "is_continuation": true | false,
  "confidence": 0.0-1.0,
  "reasoning": "1-2 предложения на русском с указанием конкретных позиций / фраз"
}
PROMPT;
    }
}
