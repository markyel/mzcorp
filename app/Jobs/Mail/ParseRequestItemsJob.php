<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Services\Request\RequestItemPersister;
use App\Services\RequestItemParsingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.8d: автоматический content-driven парсинг позиций.
 *
 * Раньше `RequestItemParsingService` запускался только через CLI
 * (`requests:parse-items`) — каждая новая входящая заявка приходила в пул
 * с `Позиции 0`, и оператору приходилось вручную дёргать команду.
 *
 * Этот job триггерится из `IncomingMailProcessor::processIfRequest`
 * сразу после создания Request: парсит тело письма + вложения
 * (PDF / XLSX / DOCX / Vision OCR на изображениях), складывает позиции
 * через `RequestItemPersister` (он идемпотентен — повторные запуски
 * не дублируют).
 *
 * Стоимость: каждый прогон тратит OpenAI-токены (gpt-4.1 + gpt-4o Vision).
 * Идёт асинхронно через очередь, чтобы IMAP sync не блокировался на
 * 5–30 секунд парсинга на письмо.
 *
 * ShouldBeUnique: за окно 5 минут не больше одного job-а на email_message_id —
 * страховка от двойного dispatch'а из разных мест pipeline.
 */
class ParseRequestItemsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    /**
     * @param int  $emailMessageId
     * @param bool $force  Снять гейт «у Request уже есть items» (обычный
     *                    повторный dispatch — no-op). Используется при
     *                    ручном перепарсинге.
     * @param bool $reset Перед persist ОЧИСТИТЬ все RequestItem-ы прицепленные
     *                    к связанной Request. Только с force=true. Нужен
     *                    когда старые items были «слеплены» прежним парсером
     *                    и новые версии не дедупятся по строке артикула.
     *                    KB-резолвы (quality_assessment_*) живут на item,
     *                    после удаления ResolveKbJob их пересчитает с нуля.
     */
    public function __construct(
        public readonly int $emailMessageId,
        public readonly bool $force = false,
        public readonly bool $reset = false,
    ) {
    }

    public function uniqueId(): string
    {
        return sprintf('parse-items:%d', $this->emailMessageId);
    }

    public function uniqueFor(): int
    {
        return 5 * 60;
    }

    public function handle(
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
        \App\Services\Mail\FreeTextReplyEnricher $freeTextEnricher,
    ): void {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            Log::info('ParseRequestItemsJob: message missing — skip', [
                'email_message_id' => $this->emailMessageId,
            ]);

            return;
        }

        // Идемпотентность: если у привязанного Request уже есть items
        // и не задан force — пропускаем (повторный dispatch не вредит).
        if (! $this->force && $message->related_request_id) {
            $alreadyHasItems = \App\Models\RequestItem::query()
                ->where('request_id', $message->related_request_id)
                ->exists();
            if ($alreadyHasItems) {
                return;
            }
        }

        // reset=true → чистый перепарсинг: грохнуть старые items, чтобы
        // persister добавил новые без дедуп-конфликтов со «склеенными»
        // артикулами из прежних версий парсера. Только если есть к чему
        // привязываться (related_request_id).
        if ($this->reset && $this->force && $message->related_request_id) {
            $deleted = \App\Models\RequestItem::query()
                ->where('request_id', $message->related_request_id)
                ->delete();
            Log::info('ParseRequestItemsJob: reset — wiped existing items', [
                'email_message_id' => $message->id,
                'request_id' => $message->related_request_id,
                'deleted' => $deleted,
            ]);
        }

        try {
            $items = $parser->parseItemsFromInboundMessage($message);
        } catch (\Throwable $e) {
            Log::error('ParseRequestItemsJob: parser failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return; // не валим pipeline — Request уже создан, оператор увидит «Позиции 0»
        }

        if (empty($items)) {
            Log::info('ParseRequestItemsJob: empty items', [
                'email_message_id' => $message->id,
                'request_id' => $message->related_request_id,
            ]);

            // Path C (2026-05-21): для reply-сообщения без структурированных
            // позиций пробуем извлечь контекстные уточнения к существующим
            // позициям («масленка на противовесе», «по плате — модель ABC»).
            // Гейт: только если это reply к существующей заявке, не reset,
            // и нет активного ClarificationBatch (тот случай покрывает
            // ClarificationAnswerMatcher через MatchClarificationAnswersJob).
            if ($message->related_request_id && ! $this->reset) {
                $hasRecentSentBatch = \App\Models\ClarificationBatch::query()
                    ->where('request_id', $message->related_request_id)
                    ->where('status', \App\Models\ClarificationBatch::STATUS_SENT)
                    ->whereNotNull('sent_at')
                    ->where('sent_at', '<', $message->sent_at ?? now())
                    ->exists();
                if (! $hasRecentSentBatch) {
                    $request = \App\Models\Request::find($message->related_request_id);
                    if ($request !== null) {
                        try {
                            $result = $freeTextEnricher->enrich($message, $request);
                            Log::info('ParseRequestItemsJob: free-text enrichment ran', [
                                'email_message_id' => $message->id,
                                'request_id' => $message->related_request_id,
                                'suggestions' => $result['suggestions'],
                                'auto_applied' => $result['auto_applied'],
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('ParseRequestItemsJob: FreeTextReplyEnricher failed', [
                                'email_message_id' => $message->id,
                                'request_id' => $message->related_request_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            return;
        }

        try {
            // Снапшот: было ли у Request items до этого прогона.
            $hadItemsBefore = $message->related_request_id
                ? \App\Models\RequestItem::query()
                    ->where('request_id', $message->related_request_id)
                    ->exists()
                : false;

            // Phase 2: thread-aware split на reply'е.
            // Если письмо прицеплено к Request, у которой уже есть items
            // (т.е. это reply, а не trigger), запускаем второй LLM-проход:
            // отделяем «truly new» позиции от «clarifications» (уточнений
            // артикулов существующих позиций). Clarifications складываются
            // в Request->pending_clarifications, новые items идут в persist
            // как обычно. Reset-режим отключает split — там старые items
            // только что стёрты, контекста нет.
            //
            // Foundation §6.2 gate: если у Request есть отправленный
            // ClarificationBatch (sent), и inbound пришёл ПОСЛЕ batch.sent_at —
            // это гарантированно ответ на наш уточняющий вопрос (Phase 6.2),
            // НЕ «уточнение позиции от клиента». Запуск Phase 2.5
            // decideClarifications даёт ложноположительные срабатывания
            // («КМЗ» в ответе «уточните марку лифта» → LLM решает добавить
            // КМЗ как brand для двигателя). Skip.
            $clarifications = [];
            $hasRecentSentBatch = false;
            if ($message->related_request_id) {
                $hasRecentSentBatch = \App\Models\ClarificationBatch::query()
                    ->where('request_id', $message->related_request_id)
                    ->where('status', \App\Models\ClarificationBatch::STATUS_SENT)
                    ->whereNotNull('sent_at')
                    ->where('sent_at', '<', $message->sent_at ?? now())
                    ->exists();
            }
            if ($hasRecentSentBatch) {
                Log::info('ParseRequestItemsJob: skip decideClarifications — reply to active clarification batch', [
                    'email_message_id' => $message->id,
                    'request_id' => $message->related_request_id,
                ]);
            }

            if ($hadItemsBefore && ! $this->reset && $message->related_request_id && ! $hasRecentSentBatch) {
                $existing = \App\Models\RequestItem::query()
                    ->where('request_id', $message->related_request_id)
                    ->orderBy('position')
                    ->get();

                $replySnippet = (string) ($message->body_plain ?? $message->body_html ?? '');
                $split = $parser->decideClarifications(
                    newItems: $items,
                    existingItems: $existing,
                    sourceEmailMessageId: $message->id,
                    replyContextSnippet: $replySnippet,
                );

                if (! empty($split['clarifications'])) {
                    $clarifications = $split['clarifications'];
                    // Оставляем в $items только те, что LLM не пометил как
                    // clarification. Индексы перенумеровываем, чтобы persister
                    // получил чистый list.
                    $items = array_values(array_intersect_key(
                        $items,
                        array_flip($split['new_indexes']),
                    ));
                }
            }

            $result = $persister->persist($message, $items, $clarifications);

            Log::info('ParseRequestItemsJob: items persisted', [
                'email_message_id' => $message->id,
                'request_id' => $result['request']?->id,
                'new' => $result['new'],
                'dup' => $result['dup'],
                'clarifications' => $result['clarifications'] ?? 0,
                'force' => $this->force,
                'had_items_before' => $hadItemsBefore,
            ]);

            // Phase 1.9 safety check: reply прицеплен к существующей Request,
            // у Request УЖЕ были items, парсер нашёл НОВЫЕ позиции и НИ ОДНОГО
            // совпадения с существующими. Это сильный сигнал что AI clarifier
            // (или один из заголовочных уровней linker'а) ошибся, прицепив
            // reply к чужой Request — на самом деле это новая заявка.
            // Не отвязываем автоматически (риск ложной тревоги), но шумно
            // логируем как WARNING — РОП должен проверить вручную.
            if (
                $this->force
                && $hadItemsBefore
                && $result['new'] > 0
                && $result['dup'] === 0
            ) {
                Log::warning('ParseRequestItemsJob: suspicious thread link — all items new, none match existing Request', [
                    'email_message_id' => $message->id,
                    'request_id' => $result['request']?->id,
                    'internal_code' => $result['request']?->internal_code,
                    'new_items' => $result['new'],
                    'hint' => 'Возможно reply ошибочно прицеплен к чужой Request. Проверьте через UI: тред в карточке этой заявки vs subject/body.',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ParseRequestItemsJob: persist failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
