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
        private readonly \App\Services\Supplier\SupplierInquiryService $supplierInquiries,
        private readonly \App\Services\Supplier\SupplierRegistry $supplierRegistry,
        private readonly \App\Services\Supplier\SupplierRfqClassifier $supplierRfqClassifier,
        private readonly SupplierCcInboxService $supplierCcInbox,
        private readonly \App\Services\Mail\Routing\RoutingPipeline $pipeline,
        private readonly InternalSenderDetector $internalDetector = new InternalSenderDetector(),
        private readonly CitedOutboundQuoteRouter $citedQuoteRouter = new CitedOutboundQuoteRouter(),
    ) {
    }

    public function route(EmailMessage $message): void
    {
        // Цепочка решений (RoutingPipeline, порядок — контракт): служебные
        // уведомления, rfq@, исходящие, не-inbound, петля пересылки, ящик
        // снабжения, стоп-лист, копия из другого ящика, ответы поставщиков,
        // категоризация, линкер + цитата КП, closed_won, post_sale. Остаток
        // ниже (ветка привязанной заявки, создание, правила) — шаг 4 strangler.
        $ctx = new \App\Services\Mail\Routing\RoutingContext($message);
        $decision = $this->pipeline->run($ctx);
        if ($decision !== null) {
            $this->decide($message, $decision->stage, $decision->requestId, $decision->payload);

            return;
        }

        // Факты, собранные цепочкой, для остатка route() (переносится шагом 4).
        $linkedRequest = $ctx->linkedRequest;
        $postSaleUnlinked = $ctx->postSaleUnlinked;

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

            // Внутренняя переписка сотрудников (наш отправитель, заказчика нет
            // среди получателей — напр. руководитель→менеджер с личным CC) НЕ
            // влияет на заявку: ни статус, ни attention «клиент ответил», ни
            // автопарсинг позиций. Письмо остаётся в треде (видно + доставляется),
            // но эффектов не даёт. Кейс M-2026-6071. Гейт статуса продублирован
            // в InboundIntentClassifier::classify (покрывает и крон self-heal).
            $internalOnly = ! $this->internalDetector->affectsRequestStatus($message, $linkedRequest->client_email);
            if ($internalOnly) {
                Log::info('MailRouter: internal correspondence (no client on any side) — no status/parse effects', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'from' => $message->from_email,
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
            if (! $internalOnly && $this->inboundClassifier->isApplicable($linkedRequest)) {
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
                        $this->decide($message, 'thread_spin_off', $new->id, ['source_request_id' => $linkedRequest->id, 'intent_confidence' => (float) ($intentResult['confidence'] ?? 0)]);
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

            // Approach B (подсказка, НЕ авто-форк): заявка уже в пост-КП стадии
            // (quoted/согласование/счёт/оплата/закрыто), а клиент в треде просит
            // добавить позиции / счёт на новое (intent additional_items ИЛИ
            // new_request ниже порога авто-форка) — вероятно ОТДЕЛЬНАЯ новая
            // заявка. Авто-классификацию НЕ трогаем (inbound_extension отработает
            // как раньше), но поднимаем менеджеру подсказку с кнопкой «Создать
            // новую заявку». recordSuggestion НЕ авто-применяется — auto_mode
            // для inbound_possible_new_request выключен по умолчанию.
            $intent = $intentResult['payload']['intent'] ?? null;
            $intentConf = (float) ($intentResult['confidence'] ?? 0);
            if ($shouldParse
                && $linkedRequest->status->isPostQuote()
                && (
                    $intent === 'additional_items'
                    || ($intent === 'new_request' && $intentConf < self::NEW_REQUEST_CONFIDENCE)
                )
            ) {
                try {
                    $this->aiDecisions->recordSuggestion(
                        DetectorType::InboundPossibleNewRequest,
                        $linkedRequest,
                        $message,
                        max($intentConf, 0.6),
                        ['signals' => ['intent' => $intent, 'stage' => $linkedRequest->status->value]],
                    );
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: possible-new-request suggestion failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'request_id' => $linkedRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($shouldParse && ! $internalOnly) {
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
                    // Порядок гарантирует цепочка (Bus::chain): Route стартует
                    // только после успешного Deliver; если Deliver исчерпал
                    // retry — Route всё равно диспатчится из catch, чтобы письмо
                    // не осталось во «Входящих» общего ящика. Кейс M-2026-1928.
                    \App\Jobs\Mail\DeliverToManagerInboxJob::chainWithRouting(
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
                if (! $internalOnly) {
                    $this->attention->onClientReplied($linkedRequest);
                }
            } catch (\Throwable $e) {
                Log::warning('MailRouter: attention onClientReplied failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Информирование ACTING-менеджеров о новом событии по ДЕЛЕГИРОВАННОЙ
            // им заявке (отсутствующего коллеги): колокольчик + email со ссылкой.
            // Только на реальном письме клиента (не внутренняя переписка).
            try {
                if (! $internalOnly) {
                    app(\App\Services\Request\DelegatedRequestNotifier::class)
                        ->notifyActingManagers($linkedRequest);
                }
            } catch (\Throwable $e) {
                Log::warning('MailRouter: delegated activity notify failed (non-fatal)', [
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
        $processed = null;
        $createAttempted = in_array($message->category, $createCategories, true);
        if ($createAttempted) {
            $processed = $this->incoming->processIfRequest($message);
        }

        // Итоговое решение по письму (журнал mail_decisions): ответ в тред /
        // заявка создана / уже была / отклонена процессором / не заявка.
        $this->recordFinalDecision($message, $linkedRequest ?? null, $createAttempted, $processed, $postSaleUnlinked ?? false);

        $matches = $this->engine->match($message);

        if (empty($matches)) {
            $this->recordNoMatch($message);

            return;
        }

        foreach ($matches as $rule) {
            $this->applyRule($rule, $message);
        }
    }

    /**
     * Записать решение маршрутизатора по письму (журнал mail_decisions).
     * Fail-soft: никогда не влияет на обработку.
     *
     * @param  array<string, mixed>  $payload
     */
    private function decide(EmailMessage $message, string $stage, ?int $requestId = null, array $payload = []): void
    {
        app(MailDecisionRecorder::class)->record($message, $stage, $requestId, $payload);
    }

    /**
     * Итоговое решение для писем, дошедших до конца route() (без early return).
     */
    private function recordFinalDecision(
        EmailMessage $message,
        ?\App\Models\Request $linkedRequest,
        bool $createAttempted,
        ?\App\Models\Request $processed,
        bool $postSaleUnlinked,
    ): void {
        if ($linkedRequest !== null) {
            $this->decide($message, 'thread_reply', $linkedRequest->id);

            return;
        }
        if ($postSaleUnlinked) {
            $this->decide($message, 'post_sale_unlinked');

            return;
        }
        if ($createAttempted) {
            // wasRecentlyCreated теряется после fresh()/touch внутри процессора —
            // «создана в этом проходе» = заявка по этому письму моложе минуты.
            $createdNow = $processed !== null && (
                $processed->wasRecentlyCreated
                || ((int) $processed->email_message_id === (int) $message->id && $processed->created_at?->gte(now()->subMinute()))
            );
            if ($processed === null) {
                $this->decide($message, 'request_rejected');
            } elseif ($createdNow) {
                $this->decide($message, 'request_created', $processed->id);
            } else {
                $this->decide($message, 'request_existing', $processed->id);
            }

            return;
        }
        $this->decide($message, 'not_a_request');
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
     * Прогнать детектор исходящих документов по уже привязанному к заявке
     * исходящему письму: rule-based → LLM fallback → recordSuggestion
     * (auto-apply переводит заявку в Quoted/Invoiced/… при confidence ≥ порога)
     * → парсер вложений (OutboundQuote/Invoice). Идемпотентно: recordSuggestion
     * дедупит по (email_message_id, type), ParseOutboundQuoteJob — по attachment.
     *
     * Вызывается из route() на синке и из команды quotes:detect-missed-outbound
     * (добивает письма, привязанные к заявке ПОСЛЕ синка — гонка «КП раньше
     * заявки»). Сбои не фатальны (логируются, не валят синк/команду).
     */
    public function runOutboundDocumentDetection(EmailMessage $message, \App\Models\Request $request): void
    {
        // Логика вынесена в OutboundDocumentDetectionService (цепочка маршрутизации,
        // OutboundReplyHooks, quotes:detect-missed-outbound). Метод оставлен как
        // публичная точка входа.
        app(OutboundDocumentDetectionService::class)->run($message, $request);
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
