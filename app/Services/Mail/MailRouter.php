<?php

namespace App\Services\Mail;

use App\Enums\DetectorType;
use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Enums\MailRuleActionType;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\EmailMessage;
use App\Models\MailRoutingRule;
use App\Models\RoutedMail;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\DocumentDetector\InboundIntentClassifier;
use App\Services\DocumentDetector\OutboundDocumentClassifier;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline маршрутизации одного письма:
 *   inbound  → loop-guard → categorize → reply-linker → IncomingProcessor → rules engine
 *   outbound → OutgoingMailLinker (header threading + subject code +
 *              recipient open-request match). Без правил, без классификатора.
 *
 * Решение «создать Request» принимается строго по EmailCategory (gpt-4o,
 * Phase 1.8c). Старый «Level-2» классификатор (gpt-4o-mini) удалён —
 * системно ошибался на «Прошу счёт MNNNN» → accounting (см. MEMORY.md).
 *
 * Foundation §1.5 pipeline.
 */
class MailRouter
{
    /**
     * Порог уверенности LLM, при котором ответ в треде разворачивается в
     * ОТДЕЛЬНУЮ новую заявку (intent=new_request). Ниже — линкуем к текущей
     * заявке как обычный reply (безопаснее: расширение обратимо разъединением).
     */
    private const NEW_REQUEST_CONFIDENCE = 0.8;

    public function __construct(
        private readonly MailRoutingRuleEngine $engine,
        private readonly MailLabelService $labels,
        private readonly MailForwarder $forwarder,
        private readonly IncomingMailProcessor $incoming,
        private readonly MailCategoryClassifier $categorizer,
        private readonly InboundReplyLinker $replyLinker,
        private readonly OutgoingMailLinker $outgoingLinker,
        private readonly OutboundDocumentDetector $outboundDetector,
        private readonly OutboundDocumentClassifier $outboundLlmClassifier,
        private readonly InboundIntentClassifier $inboundClassifier,
        private readonly AiDecisionService $aiDecisions,
        private readonly \App\Services\Request\AttentionService $attention,
        private readonly SenderBlocklistService $blocklist,
    ) {
    }

    public function route(EmailMessage $message): void
    {
        // Phase 1.9 outbound: исходящие из Sent — линкуем к существующей
        // Request, не пропускаем через categorize/rules/IncomingProcessor
        // (это наше письмо, не клиентский запрос). Отдельная короткая ветка.
        if ($message->direction === MailDirection::Outbound) {
            try {
                $linkedRequest = $this->outgoingLinker->tryLink($message);
            } catch (\Throwable $e) {
                $linkedRequest = null;
                Log::warning('MailRouter: outgoing linker failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Обратный cross-mailbox дедуп. Наше исходящее, попавшее в копию
            // (CC) во внутренний ящик коллеги, синкается как ОТДЕЛЬНОЕ inbound
            // с тем же message_id. Если эта копия пришла РАНЬШЕ, чем outbound
            // получил related_request_id, прямой дедуп в route() её пропустил —
            // она осела rel=null + category=irrelevant. Потом на неё ложно
            // срабатывал orphan-defer линкера и плодил пустую заявку (кейс
            // M-2026-4688: «выгрузили доки?» в треде закрытой 3965). Теперь,
            // когда исходящее залинковано, ретроактивно подшиваем такие копии
            // к той же заявке + помечаем cross_mailbox_copy_of (UI-тред их
            // прячет, линкер больше не считает их orphan'ами).
            if ($linkedRequest !== null && $message->message_id) {
                $this->backfillCrossMailboxCopies($message, $linkedRequest->id);
            }

            // Phase 4 (Foundation §7.1): outbound document detector.
            // Сработает только если linker уже привязал письмо к Request —
            // иначе непонятно к чему относится «КП» (общая переписка с
            // клиентом по другим заявкам, маркетинг и т.п.).
            if ($linkedRequest !== null) {
                try {
                    // Two-tier: rule-based (быстрое, ловит явные КП/счёт по
                    // filename/keyword) → LLM fallback (gpt-4o-mini, добивает
                    // edge-cases типа «Предложение МЗ-355319.pdf» / body=«КП»
                    // / HTML-only через portal).
                    //
                    // 2026-05-25 (M-2026-1589): расширили fallback. Раньше LLM
                    // дёргался только если rule-based вернул null. Но при
                    // body-keyword без filename rule-based даёт слабые 0.60 —
                    // ниже auto-apply threshold (0.85), decision застревает
                    // в suggested. LLM (gpt-4o-mini) ВИДИТ attachment-список,
                    // body, контекст заявки — может подтвердить КП/счёт с
                    // 0.85+ → auto-apply сработает. Если LLM сам не уверен
                    // (null или ≤ rule-based) — остаёмся на rule-based 0.60.
                    $detected = $this->outboundDetector->analyze($message->fresh(), $linkedRequest);
                    $autoApplyThreshold = (float) app_setting('detector.confidence_threshold', 0.85);
                    if ($detected === null || $detected['confidence'] < $autoApplyThreshold) {
                        $llm = $this->outboundLlmClassifier->classify($message->fresh(), $linkedRequest);
                        if ($llm !== null
                            && ($detected === null || $llm['confidence'] > $detected['confidence'])
                        ) {
                            $detected = $llm;
                        }
                    }
                    if ($detected !== null) {
                        // Для outbound_declined пробрасываем suggested_closed_lost_reason
                        // и cited_phrase в payload — AiDecisionService::apply
                        // прочитает их при ClosedLost-переходе (reason + цитата).
                        $suggestionPayload = ['signals' => $detected['signals']];
                        if (isset($detected['suggested_closed_lost_reason'])) {
                            $suggestionPayload['suggested_closed_lost_reason'] = $detected['suggested_closed_lost_reason'];
                        }
                        if (isset($detected['cited_phrase']) && $detected['cited_phrase'] !== null) {
                            $suggestionPayload['cited_phrase'] = $detected['cited_phrase'];
                        }
                        $this->aiDecisions->recordSuggestion(
                            $detected['type'],
                            $linkedRequest,
                            $message,
                            (float) $detected['confidence'],
                            $suggestionPayload,
                        );

                        // Парсер исходящего КП/счёта — distill позиций+цен из PDF/XLSX/DOCX
                        // вложений (Foundation §7, расширение DocumentDetector). Dispatch
                        // async-job на каждое подходящее вложение; ShouldBeUnique по
                        // attachment_id гасит дубли.
                        $this->dispatchOutboundQuoteParsing($message, $detected['type']);
                    }
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: outbound document detector failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        if ($message->direction !== MailDirection::Inbound) {
            return;
        }

        // Антициклическая защита: письма, уже когда-то пересланные нашим
        // MailForwarder'ом, имеют заголовок X-MyLift-Forwarded или префикс
        // [MyLift forward] в subject. Не маршрутизируем их повторно.
        if ($this->isLoopMessage($message)) {
            $this->recordLoopSkipped($message);

            return;
        }

        // Стоп-лист отправителей: ДО AI-категоризации (экономим токены) и
        // ДО reply-linker (спам не должен привязываться к существующим
        // заявкам). Если отправитель в стоп-листе — помечаем письмо
        // Irrelevant с reasoning'ом, выходим без routing/parser/job'ов.
        // sender_blocklist hit_count инкрементится внутри isBlocked().
        if ($this->blocklist->isBlocked($message->from_email)) {
            $message->forceFill([
                'category' => EmailCategory::Irrelevant->value,
                'category_reasoning' => 'Blocked by sender_blocklist (from='.$message->from_email.')',
                'categorized_at' => now(),
            ])->save();

            $this->recordBlocklistSkipped($message);

            Log::info('MailRouter: skip — sender in blocklist', [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'subject' => mb_substr((string) $message->subject, 0, 80),
            ]);

            return;
        }

        // Cross-mailbox дедуп — ДО любых LLM-шагов. Когда мы APPEND'или
        // оригинал письма в личный ящик менеджера (DeliverToManagerInboxJob),
        // sync личного ящика создаёт ВТОРОЙ row в email_messages с тем же
        // Message-ID. Без этого guard'а MailRouter гнал бы его повторно
        // через gpt-4o categorize → gpt-4o-mini linker AI → parser:
        // лишние $0.01+/копия + риск создать дубль Request.
        //
        // Логика: если есть РАНЕЕ сохранённый EmailMessage с тем же
        // message_id и related_request_id != null — это копия известного
        // письма. Наследуем category + related_request_id, выходим.
        if ($message->message_id) {
            $sameIdLinked = EmailMessage::query()
                ->where('message_id', $message->message_id)
                ->where('id', '!=', $message->id)
                ->whereNotNull('related_request_id')
                ->orderBy('id')
                ->first();
            if ($sameIdLinked) {
                $artifacts = (array) ($message->detected_artifacts ?? []);
                $artifacts['cross_mailbox_copy_of'] = $sameIdLinked->id;
                $message->forceFill([
                    'related_request_id' => $sameIdLinked->related_request_id,
                    'category' => $sameIdLinked->category,
                    'category_confidence' => $sameIdLinked->category_confidence,
                    'category_intent' => $sameIdLinked->category_intent,
                    'category_reasoning' => 'Cross-mailbox copy of msg#' . $sameIdLinked->id,
                    'categorized_at' => $sameIdLinked->categorized_at ?: now(),
                    'detected_artifacts' => $artifacts,
                ])->save();

                Log::info('MailRouter: cross-mailbox copy — skip pipeline', [
                    'email_message_id' => $message->id,
                    'parent_email_message_id' => $sameIdLinked->id,
                    'related_request_id' => $sameIdLinked->related_request_id,
                    'message_id' => $message->message_id,
                ]);

                return;
            }
        }

        // Phase 1.8c: категоризация (LazyLift drop-in). Заполняет
        // email_messages.category — для дальнейших шагов (linker уровня 4
        // использует это как сигнал, парсер позиций — как gate).
        // ВАЖНО: запускается ДО replyLinker, потому что 4-й уровень linker'а
        // (от from_email) опирается на category=thread_reply / client_request.
        try {
            $this->categorizer->categorize($message);
            $message->refresh();
        } catch (\Throwable $e) {
            Log::warning('MailRouter: category classifier failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Phase 1.9 (inbound-часть): прицепить письмо к существующей Request
        // через 5 уровней — In-Reply-To / References / subject-code /
        // from_email open-requests / AI multi-choice clarifier.
        // Если linked — IncomingMailProcessor::processIfRequest сам пропустит
        // создание новой Request (idempotency check на related_request_id).
        try {
            $linkedRequest = $this->replyLinker->tryLink($message);
        } catch (\Throwable $e) {
            $linkedRequest = null;
            Log::warning('MailRouter: reply linker failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Постпродажная переписка по уже оформленному заказу (отгрузка /
        // комплектация / документы). Новую заявку НЕ создаём и менеджера НЕ
        // назначаем.
        //
        // Привязка к заказу — ТОЛЬКО при надёжном совпадении линкера по
        // заголовкам/коду и только если заказ «оплачен/закрыт» (тогда шлём
        // алерт менеджеру + доставку письма в ящик). Угадывать заказ по
        // from_email НЕЛЬЗЯ (тикет M-2026-2762: в архиве платёжки по разным
        // счетам — конкретный заказ из письма не вычислить). Если надёжной
        // привязки нет — письмо остаётся в общем ящике (info@) нетронутым,
        // без заявки и без привязки.
        if ($message->category === EmailCategory::PostSale->value) {
            $postSaleRequest = $this->resolvePostSaleRequest($linkedRequest);
            if ($postSaleRequest !== null) {
                if ($message->related_request_id !== $postSaleRequest->id) {
                    $message->forceFill(['related_request_id' => $postSaleRequest->id])->save();
                }
                $this->handlePostSaleMessage($message, $postSaleRequest);

                return;
            }

            Log::info('MailRouter: post_sale — no reliable order link, left untouched in inbox', [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
            ]);
            // category остаётся post_sale → create-гейт ниже заявку не создаёт,
            // письмо остаётся в общем ящике без привязки.
        }

        // Phase 1.9: если reply прицеплен к существующей Request — запустим
        // парсер с force=true для извлечения ДОПОЛНИТЕЛЬНЫХ позиций
        // («забыл указать ещё M-1234 - 3 шт»). RequestItemPersister
        // идемпотентен: дубликаты по article+name пропускает, новые
        // добавляет к существующей Request. category-гейт в persister'е
        // не блокирует thread_reply если related_request_id уже есть.
        //
        // ReplyParseGate отрезает «спасибо, фото прилагаю» — короткие
        // сопроводительные reply'и где Vision на attachments мог бы
        // ложно сгенерировать дубликаты позиций (см. M-2026-0759 кейс).
        if ($linkedRequest !== null) {
            // Жёсткое правило sticky direct_mailbox для reply'ев
            // (Foundation §1.5): если письмо пришло в личный ящик X,
            // а связанная Request у другого assigned-менеджера Y —
            // переподчинить Request на X. Уважаем выбор клиента.
            //
            // Гарды:
            //  - origin mailbox.type === Personal (для shared ничего не меняем);
            //  - owner существует, не archived, не unavailable до 2099 (нюанс orphan-fix);
            //  - текущий assigned_user_id !== owner_user_id (иначе нечего менять).
            //
            // После reassign Фикс А автоматически не сработает (origin owner =
            // current assigned), MailDeliverToManagerService пометит
            // «already in manager mailbox, skip» — никаких лишних копий.
            try {
                $this->applyStickyDirectMailboxOnReply($message, $linkedRequest);
                // Refresh — assigned_user_id мог поменяться внутри reassign.
                $linkedRequest = $linkedRequest->fresh() ?? $linkedRequest;
            } catch (\Throwable $e) {
                Log::warning('MailRouter: sticky direct_mailbox reassign failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $shouldParse = true;
            try {
                $shouldParse = app(\App\Services\Mail\ReplyParseGate::class)
                    ->shouldParse($message);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: ReplyParseGate failed (default to parse)', [
                    'email_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Foundation §7.2 + расширение vs новая заявка: классифицируем
            // intent ОДИН раз ЗДЕСЬ (до парсинга), чтобы при new_request успеть
            // развернуть письмо в ОТДЕЛЬНУЮ заявку ДО того, как парсер добавит
            // позиции в текущую. Результат переиспользуем ниже для
            // recordSuggestion (повторного LLM-вызова нет).
            $intentResult = null;
            if ($this->inboundClassifier->isApplicable($linkedRequest)) {
                try {
                    $intentResult = $this->inboundClassifier->classify($message->fresh(), $linkedRequest);
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: inbound intent classifier failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'request_id' => $linkedRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // new_request: клиент прислал СОВЕРШЕННО НОВУЮ заявку в старом треде.
            // Разворачиваем письмо в отдельную Request (с авто-назначением),
            // текущую не трогаем и выходим. Гейт: сигналы позиций (shouldParse) +
            // порог уверенности. Ошибка LLM обратима (merge назад).
            if ($shouldParse
                && ($intentResult['payload']['intent'] ?? null) === 'new_request'
                && (float) ($intentResult['confidence'] ?? 0) >= self::NEW_REQUEST_CONFIDENCE
            ) {
                try {
                    $new = app(\App\Services\Request\RequestExtensionService::class)
                        ->spinOffNewRequest($message, $linkedRequest);
                    if ($new !== null) {
                        return;
                    }
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: new_request spin-off failed (fallback to reply)', [
                        'email_message_id' => $message->id,
                        'request_id' => $linkedRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                // спин-офф не удался — продолжаем как обычный reply ниже.
            }

            if ($shouldParse) {
                \App\Jobs\Mail\ParseRequestItemsJob::dispatch($message->id, true);
            }

            // Routing reply'я в подпапку MZ|{Lastname} общего ящика +
            // доставка в личный INBOX менеджера. Раньше эти шаги отрабатывали
            // только при создании Request (AssignmentService) и в success-
            // ветке Persister'а после парсинга. Для reply'ев без новых
            // позиций (типа «выставите счёт», forward'ы с уточнениями)
            // парсер заканчивал с items=[], routing-fallback не дёргался,
            // и письмо застревало в INBOX общего ящика без копии в личный.
            // Кейс M-2026-1928: msg#4712, msg#4806 от pto@trastlift.ru —
            // Васюхно получил только первое письмо, остальные висели
            // непрочитанными на info@. Делаем async через те же jobs что
            // используются при назначении менеджера.
            if ($linkedRequest->assigned_user_id) {
                try {
                    // ВАЖНО: Deliver ПЕРВЫМ — он re-fetch'ит полный RFC822
                    // из source-папки по imap_uid. Если Route отработает
                    // раньше (UID MOVE в MZ|*), исходный UID в INBOX
                    // становится невалидным, и Deliver падает на re-fetch
                    // failed → cannot reconstruct RFC822 → skip APPEND (молча,
                    // без retry — fetchFullRfc822 не throw'ит).
                    // FIFO в очереди не строгий, но dispatch порядок даёт
                    // приоритет первому job'у. Кейс M-2026-1928 msg#4712.
                    \App\Jobs\Mail\DeliverToManagerInboxJob::dispatch(
                        $message->id,
                        $linkedRequest->assigned_user_id,
                    );
                    \App\Jobs\Mail\RouteMailToManagerJob::dispatch(
                        $message->id,
                        $linkedRequest->assigned_user_id,
                    );
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: dispatch reply routing/delivery failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'request_id' => $linkedRequest->id,
                        'manager_id' => $linkedRequest->assigned_user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Attention «📨 Ответ от клиента» — пометить заявку «есть новости»
            // на любом не-терминальном статусе. onClientReplied сам пропустит
            // silent-статусы (Pending/Paused/Closed*/Paid). Если ниже
            // InboundIntentClassifier auto-apply'ит transition (например,
            // postponed_resume на дате) — последующий transitionTo()
            // вызовет recompute() и затрёт ClientReplied на SlaBreach или
            // null. Если intent не auto-apply'нулся — ClientReplied остаётся
            // до Detail::mount менеджера (onManagerOpened).
            try {
                $this->attention->onClientReplied($linkedRequest);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: attention onClientReplied failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Phase 4 (Foundation §7.2): записываем suggestion по intent'у,
            // классифицированному ВЫШЕ (до парсинга). type === null — это
            // new_request, не развёрнутый в новую заявку (низкая уверенность
            // или сбой spin-off): перехода статуса нет, suggestion не пишем.
            if ($intentResult !== null && ($intentResult['type'] ?? null) !== null) {
                try {
                    $this->aiDecisions->recordSuggestion(
                        $intentResult['type'],
                        $linkedRequest,
                        $message,
                        (float) $intentResult['confidence'],
                        $intentResult['payload'],
                    );
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: recordSuggestion failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'request_id' => $linkedRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Foundation §6.2 Phase B/C: если у Request есть pending
            // clarification batches (sent, без answered_at) — async LLM-job
            // сматчит ответ клиента с конкретными вопросами и извлечёт
            // enrichment suggestions (артикул / бренд / qty).
            try {
                $pendingBatches = \App\Models\ClarificationBatch::query()
                    ->where('request_id', $linkedRequest->id)
                    ->where('status', \App\Models\ClarificationBatch::STATUS_SENT)
                    ->whereNull('answered_at')
                    ->pluck('id');
                foreach ($pendingBatches as $batchId) {
                    \App\Jobs\Mail\MatchClarificationAnswersJob::dispatch($message->id, $batchId);
                }
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch clarification matcher failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Оживляющее письмо (RevivalOffer): reply на проигранную заявку, по
            // которой отправлено оживляющее письмо без ответа — async LLM-job
            // классифицирует согласие клиента и при положительном реанимирует.
            try {
                if ($linkedRequest->status === \App\Enums\RequestStatus::ClosedLost) {
                    $revivalSent = \App\Models\ClientNotificationSent::query()
                        ->where('request_id', $linkedRequest->id)
                        ->where('type', \App\Enums\ClientNotificationType::RevivalOffer->value)
                        ->whereNull('responded_at')
                        ->orderByDesc('id')
                        ->first();
                    if ($revivalSent) {
                        \App\Jobs\Mail\RevivalReplyMatcherJob::dispatch($message->id, $revivalSent->id);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch revival reply matcher failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Phase 1.8: для писем-заявок создаём Request. Решение принимается
        // по EmailCategory. IncomingMailProcessor идемпотентен по
        // related_request_id (reply linker мог уже прицепить письмо к
        // существующей заявке выше).
        //
        // ThreadReply тоже передаём — внутри процессор создаст Request только
        // если linker не нашёл existing (related_request_id null). Это спасает
        // «висящие» thread_reply'и: клиент сделал forward старой переписки,
        // у нас в БД нет того треда — без fallback'а заявка не создавалась.
        // post_sale обрабатывается выше: либо привязка к оплаченному/закрытому
        // заказу (early return), либо «нет подходящего заказа → оставить письмо
        // в общем ящике без заявки». До сюда post_sale доходит только во втором
        // случае, и create-гейт ниже его не подхватывает (нет в createCategories) —
        // новую заявку не создаём (тикет M-2026-2706).
        $createCategories = [
            EmailCategory::ClientRequest->value,
            EmailCategory::ThreadReply->value,
        ];
        if (in_array($message->category, $createCategories, true)) {
            $this->incoming->processIfRequest($message);
        }

        $matches = $this->engine->match($message);

        if (empty($matches)) {
            $this->recordNoMatch($message);

            return;
        }

        foreach ($matches as $rule) {
            $this->applyRule($rule, $message);
        }
    }

    private function applyRule(MailRoutingRule $rule, EmailMessage $message): void
    {
        $audit = new RoutedMail([
            'email_message_id' => $message->id,
            'rule_id' => $rule->id,
            'ai_classified_as' => $message->category,
            'action_taken' => $rule->action_type->value,
            'forwarded_to' => $rule->forward_to_email,
            'label_applied' => $rule->label,
            'success' => true,
            'processed_at' => now(),
        ]);

        try {
            switch ($rule->action_type) {
                case MailRuleActionType::Forward:
                    if ($rule->forward_to_email) {
                        $ok = $this->forwarder->forward($message, $rule->forward_to_email, $rule->name);
                        if (! $ok) {
                            $audit->success = false;
                            $audit->error_message = 'forward failed (см. лог)';
                        }
                    }
                    if ($rule->label) {
                        $this->labels->applyLabel($message, $rule->label);
                    }
                    break;

                case MailRuleActionType::LabelOnly:
                    if ($rule->label) {
                        $ok = $this->labels->applyLabel($message, $rule->label);
                        if (! $ok) {
                            $audit->success = false;
                            $audit->error_message = 'label apply failed (см. лог)';
                        }
                    }
                    break;

                case MailRuleActionType::TriggerRequestCreation:
                    // Phase 1.8: создание Request из IncomingMailProcessor.
                    if ($rule->label) {
                        $this->labels->applyLabel($message, $rule->label);
                    }
                    break;
            }

            $rule->increment('match_count');
        } catch (\Throwable $e) {
            $audit->success = false;
            $audit->error_message = mb_substr($e->getMessage(), 0, 1000);
            Log::error('MailRouter: rule application failed', [
                'rule_id' => $rule->id,
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        $audit->save();
    }

    private function recordNoMatch(EmailMessage $message): void
    {
        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->category,
            'action_taken' => 'none',
            'success' => true,
            'processed_at' => now(),
        ]);
    }

    /**
     * Подшить ранее пришедшие копии того же письма (тот же message_id,
     * rel=null) к заявке $requestId и пометить cross_mailbox_copy_of.
     *
     * Закрывает гонку «копия (CC во внутренний ящик) пришла раньше, чем
     * оригинал/исходящее получило related_request_id» — без этого копия
     * оставалась rel=null orphan'ом и линкер плодил из reply'ев пустые
     * заявки (M-2026-4688). message_id уникален глобально, поэтому все
     * совпадения — гарантированно копии ОДНОГО письма → одна заявка.
     */
    private function backfillCrossMailboxCopies(EmailMessage $message, int $requestId): void
    {
        $copies = EmailMessage::query()
            ->where('message_id', $message->message_id)
            ->where('id', '!=', $message->id)
            ->whereNull('related_request_id')
            ->get();

        foreach ($copies as $copy) {
            $artifacts = (array) ($copy->detected_artifacts ?? []);
            $artifacts['cross_mailbox_copy_of'] = $message->id;
            $copy->forceFill([
                'related_request_id' => $requestId,
                'detected_artifacts' => $artifacts,
            ])->save();

            Log::info('MailRouter: backfilled prior cross-mailbox copy to request', [
                'copy_email_message_id' => $copy->id,
                'source_email_message_id' => $message->id,
                'request_id' => $requestId,
                'message_id' => $message->message_id,
            ]);
        }
    }

    private function recordLoopSkipped(EmailMessage $message): void
    {
        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->category,
            'action_taken' => 'loop_skipped',
            'success' => true,
            'processed_at' => now(),
        ]);
        Log::info('MailRouter: loop guard triggered, skipping rules', [
            'email_message_id' => $message->id,
            'subject' => mb_substr((string) $message->subject, 0, 80),
            'from' => $message->from_email,
        ]);
    }

    private function recordBlocklistSkipped(EmailMessage $message): void
    {
        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->category,
            'action_taken' => 'blocklist_skipped',
            'success' => true,
            'processed_at' => now(),
        ]);
    }

    /**
     * Письмо — это вернувшийся к нам наш собственный forward?
     * Проверяем по X-MyLift-Forwarded заголовку и subject-префиксу.
     */
    private function isLoopMessage(EmailMessage $message): bool
    {
        $headers = (array) ($message->headers ?? []);
        // headers — jsonb; ключи могут быть в любом регистре.
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'X-MyLift-Forwarded') === 0) {
                return true;
            }
            if (strcasecmp((string) $name, 'x_mylift_forwarded') === 0) {
                return true;
            }
        }

        $subject = (string) $message->subject;
        if (str_starts_with($subject, '[MyLift forward]')) {
            return true;
        }

        return false;
    }

    /**
     * Постпродажное письмо по успешно закрытой (closed_won) заявке.
     *
     * Заявку НЕ реанимируем (сделка состоялась). Делаем две вещи:
     *   1. attention=PostSale — закрытая заявка всплывёт в отдельной секции
     *      Pool менеджера «Постпродажная переписка» (новый комментарий клиента).
     *   2. Доставляем письмо в личный ящик менеджера + раскладываем в подпапку
     *      MZ|{Lastname} — теми же job'ами, что и для обычного reply'я, чтобы
     *      менеджер видел переписку в почте.
     *
     * Парсер позиций НЕ запускаем — новых позиций в постпродаже нет.
     */
    /**
     * Найти заказ клиента, к которому относится постпродажное письмо
     * (тикет M-2026-2706). «Оплаченный/закрытый» заказ = статус
     * awaiting_invoice / invoiced / paid / closed_won.
     *
     * Приоритет:
     *  1) заказ, который линкер уже нашёл по заголовкам/коду — если его статус
     *     попадает в «оплачен/закрыт»;
     *  2) последний (по created_at) заказ клиента в этих статусах по from_email.
     *
     * null = подходящего заказа нет — заявку создавать не нужно, письмо
     * остаётся в общем ящике.
     */
    private function resolvePostSaleRequest(?\App\Models\Request $linkedRequest): ?\App\Models\Request
    {
        // Привязываем post_sale письмо к заказу ТОЛЬКО при надёжном совпадении
        // линкера по заголовкам/коду (In-Reply-To / References / subject-code)
        // и только если этот заказ «оплачен/закрыт». Нечёткий поиск «последнего
        // оплаченного заказа по from_email» УБРАН: он угадывал не тот заказ
        // (тикет M-2026-2762). Нет надёжной привязки — письмо остаётся в ящике.
        if ($linkedRequest === null) {
            return null;
        }

        $postSaleStatuses = [
            \App\Enums\RequestStatus::AwaitingInvoice->value,
            \App\Enums\RequestStatus::Invoiced->value,
            \App\Enums\RequestStatus::Paid->value,
            \App\Enums\RequestStatus::ClosedWon->value,
        ];

        return in_array($linkedRequest->status?->value, $postSaleStatuses, true)
            ? $linkedRequest
            : null;
    }

    private function handlePostSaleMessage(EmailMessage $message, \App\Models\Request $request): void
    {
        try {
            $this->attention->onPostSaleMessage($request);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: attention onPostSaleMessage failed (non-fatal)', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($request->assigned_user_id) {
            try {
                \App\Jobs\Mail\DeliverToManagerInboxJob::dispatch(
                    $message->id,
                    $request->assigned_user_id,
                );
                \App\Jobs\Mail\RouteMailToManagerJob::dispatch(
                    $message->id,
                    $request->assigned_user_id,
                );
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch post-sale routing/delivery failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $request->id,
                    'manager_id' => $request->assigned_user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('MailRouter: post-sale message handled on closed_won request', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'manager_id' => $request->assigned_user_id,
        ]);
    }

    /**
     * Триггер парсера исходящих КП/счетов (Foundation §7, расширение).
     *
     * Отбор: тип события — quotation/invoice (clarification и прочие игнорируем),
     * расширение вложения — из `services.quotes.parseable_extensions`. Размер +
     * наличие файла проверяет уже сам job (Guard'ы в handle()).
     *
     * Идемпотентность гарантируется `ParseOutboundQuoteJob::uniqueId()` по
     * attachment_id — повторный запуск через MailRouter (sync пересортировка)
     * не плодит дубли.
     */
    private function dispatchOutboundQuoteParsing(EmailMessage $message, DetectorType $letterType): void
    {
        // Парсим только КП и счёт. Clarification и outbound_quotation_partial
        // (partial — пока зарезервирован, не используется детектором) — пропускаем.
        if (! in_array($letterType, [DetectorType::OutboundQuotationFull, DetectorType::OutboundInvoice], true)) {
            return;
        }

        $parseable = (array) config('services.quotes.parseable_extensions', ['pdf', 'xlsx', 'xls', 'docx']);

        foreach ($message->attachments as $att) {
            $ext = strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION));
            if (! in_array($ext, $parseable, true)) {
                continue;
            }
            // Письмо может нести И КП, И счёт одновременно (M-2026-3456). Тип письма
            // (priority invoice > quotation) задаёт статус заявки, но КАЖДОЕ вложение
            // парсится по СВОЕМУ типу: «Счет …» → outbound_invoice (→ Invoice),
            // «Предложение …» → outbound_quotation_full. Файлы, что по имени не
            // самоопределяются (спецификация, doc.pdf), наследуют тип письма.
            $attType = $this->outboundDetector->classifyAttachmentByFilename((string) $att->filename)
                ?? $letterType;
            try {
                ParseOutboundQuoteJob::dispatch($att->id, $attType->value, false);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch outbound quote parser failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'attachment_id' => $att->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Жёсткое правило «личный ящик X → Request у X» применённое для reply'ев
     * (Foundation §1.5, parallel со sticky Level 0 в AssignmentService для
     * новых заявок).
     *
     * Кейс M-2026-1651 / msg#5298 (27.05): клиент написал reply лично
     * Курзаеву (РОП), но linker по In-Reply-To привязал к Request у Головнева.
     * Раньше assigned не пересчитывался — Головнев оставался owner, мы
     * APPEND'или копию ему (commit 19e2957). Двойной learn: Фикс А блокирует
     * дубль APPEND'а, Fix Б (этот метод) — переподчиняет Request на того,
     * кому клиент адресовал письмо. Уважаем выбор клиента.
     *
     * Гарды:
     *  - origin mailbox.type === Personal;
     *  - owner существует, не archived, не в долгом unavailable;
     *  - текущий assigned_user_id !== owner_user_id.
     */
    private function applyStickyDirectMailboxOnReply(
        EmailMessage $message,
        \App\Models\Request $request,
    ): void {
        $mailbox = $message->mailbox;
        if (! $mailbox || $mailbox->type !== \App\Enums\MailboxType::Personal) {
            return;
        }
        $ownerId = $mailbox->owner_user_id;
        if (! $ownerId) {
            return;
        }
        if ((int) $request->assigned_user_id === (int) $ownerId) {
            return;
        }

        $owner = $mailbox->owner;
        if (! $owner) {
            return;
        }
        if ($owner->archived_at !== null) {
            return;
        }
        if ($owner->unavailable_until !== null && $owner->unavailable_until->isFuture()) {
            // В долгом отпуске — не вешаем заявку на него, оставляем текущего.
            return;
        }

        Log::info('MailRouter: sticky direct_mailbox reassign on reply', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'from_user_id' => $request->assigned_user_id,
            'to_user_id' => $owner->id,
            'origin_mailbox' => $mailbox->email,
        ]);

        app(\App\Services\Request\ReassignService::class)->reassign(
            request: $request,
            newAssignee: $owner,
            reason: 'sticky_direct_mailbox_on_reply email_message_id=' . $message->id,
            by: null, // system-actor — переподчинение по правилу, не вручную
        );
    }
}
