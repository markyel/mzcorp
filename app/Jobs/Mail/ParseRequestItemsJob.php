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

        // Метим связанную Request как «парсер закончил» — независимо от исхода.
        // UI карточки по этому полю снимает индикатор «парсится…» и
        // прекращает wire:poll. Вызов идёт в finally-стиле через try/finally
        // ниже, обёрнутый ниже основной логики.
        $markFinished = function () use ($message): void {
            if (! $message->related_request_id) {
                return;
            }
            $req = \App\Models\Request::find($message->related_request_id);
            if (! $req) {
                return;
            }
            $meta = is_array($req->parsing_meta) ? $req->parsing_meta : [];
            $meta['parser_finished_at'] = now()->toIso8601String();
            $req->forceFill(['parsing_meta' => $meta])->save();
        };

        try {
            $this->doParse($message, $parser, $persister, $freeTextEnricher);
        } finally {
            // Снимаем индикатор «парсится…» с карточки в любом случае:
            // успех, empty, парсер упал — отметка «job отработал» нужна
            // одинаково. Свой fail-soft try внутри — чтобы исключение в
            // markFinished не подменило исходное исключение из doParse.
            try {
                $markFinished();
            } catch (\Throwable $e) {
                Log::warning('ParseRequestItemsJob: markFinished failed', [
                    'email_message_id' => $this->emailMessageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Тело job'а, вынесенное чтобы handle() мог обернуть его в try/finally
     * и одинаково для всех исходов проставить parsing_meta.parser_finished_at.
     */
    private function doParse(
        EmailMessage $message,
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
        \App\Services\Mail\FreeTextReplyEnricher $freeTextEnricher,
    ): void {
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

            // Inheritance fallback (2026-05-26): linker нашёл terminal parent
            // (typically Liftway-style partner reminder: subject «Re: …
            // — #LZ-REQ-NNNN», parent closed_lost), parser вернул []
            // (промпт Пример 8: «reply-напоминание → пусто»). Без этого
            // child Request висит pending без позиций.
            //
            // Если в detected_artifacts.inheritance_candidate_id есть parent
            // и child пустой — клонируем активные items + связываем как
            // inheritance child + dispatch assignment + folder routing.
            if ($message->related_request_id && ! $this->reset) {
                $adopted = $this->tryAdoptFromInheritanceCandidate($message);
                if ($adopted) {
                    return; // успешно унаследовали — free-text enrichment пропускаем
                }
            }

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

            // Extra-info из структурных вложений (xlsx/pdf/docx): серийник
            // лифта, модель, объект, договор, желаемая дата. Складывается
            // в requests.parsing_meta.attachment_extracted[] для блока
            // «Справочно из файлов» на карточке. Fail-soft — любая ошибка
            // не валит persist.
            if ($result['request'] !== null) {
                try {
                    app(\App\Services\Mail\AttachmentMetaExtractionApplier::class)
                        ->applyForMessage($message, $result['request']);
                } catch (\Throwable $e) {
                    Log::warning('ParseRequestItemsJob: attachment meta extraction failed', [
                        'email_message_id' => $message->id,
                        'request_id' => $result['request']->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Reply-routing fallback: письмо-напоминание клиента («прошу
            // ускорить») может не содержать новых позиций — Persister
            // early-return'ит на пустых items+clarifications, routing не
            // выполняется, письмо остаётся в INBOX вместо папки менеджера.
            // Если письмо привязано к существующей Request (InboundReplyLinker)
            // у которой назначен менеджер — двигаем в его папку.
            try {
                $message->refresh();
                $folderStr = (string) $message->folder;
                // Yandex IMAP использует «|» как разделитель папок, остальные «/».
                $alreadyRouted = str_contains($folderStr, 'MZ/') || str_contains($folderStr, 'MZ|');
                $needsManualRoute = $message->related_request_id && ! $alreadyRouted;
                if ($needsManualRoute) {
                    $related = \App\Models\Request::query()
                        ->whereKey($message->related_request_id)
                        ->with('assignedUser')
                        ->first();
                    if ($related && $related->assigned_user_id && $related->assignedUser) {
                        try {
                            app(\App\Services\Mail\MailFolderRouter::class)
                                ->routeToManager($message, $related->assignedUser);
                        } catch (\App\Exceptions\Mail\TransientImapException $e) {
                            // Yandex-flake: перекладываем на async Job с
                            // backoff'ом (см. RouteMailToManagerJob::tries=5).
                            Log::info('ParseRequestItemsJob: reply-routing transient fail, dispatching async retry', [
                                'email_message_id' => $message->id,
                                'manager_id' => $related->assigned_user_id,
                                'error' => $e->getMessage(),
                            ]);
                            \App\Jobs\Mail\RouteMailToManagerJob::dispatch($message->id, $related->assigned_user_id)
                                ->delay(now()->addSeconds(30));
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ParseRequestItemsJob: reply-routing fallback failed', [
                    'email_message_id' => $message->id,
                    'related_request_id' => $message->related_request_id,
                    'error' => $e->getMessage(),
                ]);
            }

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

    /**
     * Унаследовать позиции из inheritance candidate (terminal parent),
     * если linker такое зафиксировал в detected_artifacts.
     *
     * Дёргается из doParse() когда парсер вернул [] И related_request_id
     * есть (Request 1839, 1838, ... — Liftway-style reminders).
     *
     * @return bool true — успешно унаследовали (вызвали adoptFromParent
     *              + AssignmentService + MailFolderRouter); false — кандидата
     *              нет / parent невалиден / child уже имеет items / ошибка.
     */
    private function tryAdoptFromInheritanceCandidate(EmailMessage $message): bool
    {
        $artifacts = is_array($message->detected_artifacts) ? $message->detected_artifacts : [];
        $candidateId = (int) ($artifacts['inheritance_candidate_id'] ?? 0);
        if ($candidateId <= 0) {
            return false;
        }

        $child = \App\Models\Request::find($message->related_request_id);
        if (! $child) {
            return false;
        }
        // Идемпотентность: если уже привязан к родителю — пропускаем.
        if ($child->inheritance_parent_id) {
            return false;
        }
        $parent = \App\Models\Request::find($candidateId);
        if (! $parent) {
            return false;
        }

        try {
            $inheritance = app(\App\Services\Request\RequestInheritanceService::class);
            $inheritance->adoptFromParent($child, $parent, linkedBy: 'system_reminder_fallback');
        } catch (\Throwable $e) {
            Log::warning('ParseRequestItemsJob: adoptFromParent failed', [
                'email_message_id' => $message->id,
                'child_request_id' => $child->id,
                'parent_request_id' => $parent->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Assignment + routing: child Request теперь имеет позиции, его
        // нужно назначить (sticky подтянет parent's manager) + положить
        // оригинал письма в личный ящик менеджера.
        try {
            $child = $child->fresh();
            if (! $child->assigned_user_id) {
                $assignment = app(\App\Services\Request\AssignmentService::class);
                $assignment->autoAssign($child);
                $child = $child->fresh();
            }
            if ($child->assigned_user_id) {
                $manager = \App\Models\User::find($child->assigned_user_id);
                if ($manager) {
                    try {
                        app(\App\Services\Mail\MailFolderRouter::class)
                            ->routeToManager($message->fresh(), $manager);
                    } catch (\App\Exceptions\Mail\TransientImapException $e) {
                        \App\Jobs\Mail\RouteMailToManagerJob::dispatch($message->id, $manager->id)
                            ->delay(now()->addSeconds(30));
                    } catch (\Throwable $e) {
                        Log::warning('ParseRequestItemsJob: adopt routing failed (non-fatal)', [
                            'email_message_id' => $message->id,
                            'manager_id' => $manager->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ParseRequestItemsJob: adopt post-assignment failed', [
                'email_message_id' => $message->id,
                'child_request_id' => $child->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('ParseRequestItemsJob: adopted from inheritance candidate', [
            'email_message_id' => $message->id,
            'child_request_id' => $child->id,
            'parent_request_id' => $parent->id,
            'parent_status' => is_object($parent->status) ? $parent->status->value : $parent->status,
        ]);

        return true;
    }
}
