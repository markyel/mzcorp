<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\ClientNotificationType;
use App\Enums\DetectorType;
use App\Enums\MailboxType;
use App\Enums\RequestStatus;
use App\Jobs\Mail\DeliverToManagerInboxJob;
use App\Jobs\Mail\MatchClarificationAnswersJob;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Jobs\Mail\RevivalReplyMatcherJob;
use App\Models\ClarificationBatch;
use App\Models\ClientNotificationSent;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\DocumentDetector\InboundIntentClassifier;
use App\Services\Mail\InternalSenderDetector;
use App\Services\Mail\ReplyParseGate;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Request\AttentionService;
use App\Services\Request\DelegatedRequestNotifier;
use App\Services\Request\ReassignService;
use App\Services\Request\RequestExtensionService;
use Illuminate\Support\Facades\Log;

/**
 * Письмо привязано к существующей ОТКРЫТОЙ заявке (ответ клиента в тред):
 *  1. sticky direct_mailbox — письмо пришло в личный ящик X, а заявка у Y →
 *     переподчинить на X (Foundation §1.5, кейс M-2026-1651);
 *  2. внутренняя переписка сотрудников (клиента нет ни с одной стороны) —
 *     без эффектов на статус/attention/парсинг (кейс M-2026-6071);
 *  3. ReplyParseGate — есть ли в письме сигналы позиций;
 *  4. LLM-интент ответа (один раз, до парсинга): new_request с уверенностью ≥
 *     порога → спин-офф в отдельную заявку; additional_items на пост-КП
 *     стадии → подсказка «возможно новая заявка»;
 *  5. парсер дополнительных позиций (force), доставка/перенос письма менеджеру
 *     (Deliver → Route цепочкой), attention «ответ клиента», уведомление
 *     acting-менеджеров, запись suggestion по интенту, матчинг ответов на
 *     уточнения, матчинг ответа на оживляющее письмо.
 *
 * Перенесено из MailRouter::route() без изменений логики (2026-09-09).
 */
final class LinkedThreadHandler implements InboundRoutingHandler
{
    /**
     * Порог уверенности LLM, при котором ответ в треде разворачивается в
     * ОТДЕЛЬНУЮ новую заявку (intent=new_request). Ниже — линкуем к текущей
     * заявке как обычный reply (безопаснее: расширение обратимо разъединением).
     */
    private const NEW_REQUEST_CONFIDENCE = 0.8;

    public function __construct(
        private readonly InternalSenderDetector $internalDetector,
        private readonly ReplyParseGate $parseGate,
        private readonly InboundIntentClassifier $inboundClassifier,
        private readonly AiDecisionService $aiDecisions,
        private readonly AttentionService $attention,
        private readonly RequestExtensionService $extension,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $linkedRequest = $ctx->linkedRequest;
        if ($linkedRequest === null) {
            return null;
        }

        // Жёсткое правило sticky direct_mailbox для reply'ев (Foundation §1.5):
        // если письмо пришло в личный ящик X, а связанная Request у другого
        // assigned-менеджера Y — переподчинить Request на X. Уважаем выбор клиента.
        // После reassign MailDeliverToManagerService пометит «already in manager
        // mailbox, skip» — никаких лишних копий.
        try {
            $this->applyStickyDirectMailboxOnReply($message, $linkedRequest);
            // Refresh — assigned_user_id мог поменяться внутри reassign.
            $linkedRequest = $linkedRequest->fresh() ?? $linkedRequest;
            $ctx->linkedRequest = $linkedRequest;
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

        // ReplyParseGate отрезает «спасибо, фото прилагаю» — короткие
        // сопроводительные reply'и, где Vision на attachments мог бы ложно
        // сгенерировать дубликаты позиций (см. M-2026-0759).
        $shouldParse = true;
        try {
            $shouldParse = $this->parseGate->shouldParse($message);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: ReplyParseGate failed (default to parse)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Foundation §7.2 + расширение vs новая заявка: классифицируем intent
        // ОДИН раз ЗДЕСЬ (до парсинга), чтобы при new_request успеть развернуть
        // письмо в ОТДЕЛЬНУЮ заявку ДО того, как парсер добавит позиции в
        // текущую. Результат переиспользуем ниже для recordSuggestion.
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
                $new = $this->extension->spinOffNewRequest($message, $linkedRequest);
                if ($new !== null) {
                    return new RoutingDecision('thread_spin_off', $new->id, [
                        'source_request_id' => $linkedRequest->id,
                        'intent_confidence' => (float) ($intentResult['confidence'] ?? 0),
                    ]);
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

        // Approach B (подсказка, НЕ авто-форк): заявка уже в пост-КП стадии, а
        // клиент в треде просит добавить позиции / счёт на новое (intent
        // additional_items ИЛИ new_request ниже порога) — вероятно ОТДЕЛЬНАЯ
        // новая заявка. Поднимаем менеджеру подсказку «Создать новую заявку»;
        // auto_mode для inbound_possible_new_request выключен по умолчанию.
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

        // Парсер дополнительных позиций («забыл указать ещё M-1234 - 3 шт»).
        // RequestItemPersister идемпотентен: дубликаты по article+name
        // пропускает, новые добавляет к существующей Request.
        if ($shouldParse && ! $internalOnly) {
            ParseRequestItemsJob::dispatch($message->id, true);
        }

        // Доставка reply'я в личный INBOX менеджера + перенос в подпапку
        // MZ|{Lastname} общего ящика — цепочкой Deliver → Route (Route меняет
        // UID, по которому Deliver re-fetch'ит RFC822). Кейс M-2026-1928.
        if ($linkedRequest->assigned_user_id) {
            try {
                DeliverToManagerInboxJob::chainWithRouting($message->id, $linkedRequest->assigned_user_id);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch reply routing/delivery failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'manager_id' => $linkedRequest->assigned_user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Attention «📨 Ответ от клиента» — пометить заявку «есть новости» на
        // любом не-терминальном статусе (onClientReplied сам пропустит
        // silent-статусы).
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
        // им заявке: колокольчик + email. Только на реальном письме клиента.
        try {
            if (! $internalOnly) {
                app(DelegatedRequestNotifier::class)->notifyActingManagers($linkedRequest);
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: delegated activity notify failed (non-fatal)', [
                'email_message_id' => $message->id,
                'request_id' => $linkedRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Phase 4 (Foundation §7.2): suggestion по intent'у, классифицированному
        // выше. type === null — new_request, не развёрнутый в новую заявку:
        // перехода статуса нет, suggestion не пишем.
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

        // Foundation §6.2 Phase B/C: pending clarification batches (sent, без
        // answered_at) — async LLM-job сматчит ответ клиента с вопросами.
        try {
            $pendingBatches = ClarificationBatch::query()
                ->where('request_id', $linkedRequest->id)
                ->where('status', ClarificationBatch::STATUS_SENT)
                ->whereNull('answered_at')
                ->pluck('id');
            foreach ($pendingBatches as $batchId) {
                MatchClarificationAnswersJob::dispatch($message->id, $batchId);
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
            if ($linkedRequest->status === RequestStatus::ClosedLost) {
                $revivalSent = ClientNotificationSent::query()
                    ->where('request_id', $linkedRequest->id)
                    ->where('type', ClientNotificationType::RevivalOffer->value)
                    ->whereNull('responded_at')
                    ->orderByDesc('id')
                    ->first();
                if ($revivalSent) {
                    RevivalReplyMatcherJob::dispatch($message->id, $revivalSent->id);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: dispatch revival reply matcher failed (non-fatal)', [
                'email_message_id' => $message->id,
                'request_id' => $linkedRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Ответ в тред: заявка уже есть — процессор создания ничего не сделает,
        // правила маршрутизации (forward/label) прогоняем как раньше.
        return new RoutingDecision('thread_reply', $linkedRequest->id, [], runRules: true);
    }

    /**
     * Жёсткое правило «личный ящик X → Request у X», применённое для reply'ев
     * (Foundation §1.5, parallel со sticky Level 0 в AssignmentService).
     * Кейс M-2026-1651 / msg#5298: клиент написал reply лично Курзаеву (РОП),
     * но linker по In-Reply-To привязал к Request у Головнева. Уважаем выбор
     * клиента. Гарды: origin mailbox — Personal; owner существует, не archived,
     * не в долгом unavailable; текущий assigned_user_id !== owner_user_id.
     */
    private function applyStickyDirectMailboxOnReply(EmailMessage $message, Request $request): void
    {
        $mailbox = $message->mailbox;
        if (! $mailbox || $mailbox->type !== MailboxType::Personal) {
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

        app(ReassignService::class)->reassign(
            request: $request,
            newAssignee: $owner,
            reason: 'sticky_direct_mailbox_on_reply email_message_id=' . $message->id,
            by: null, // system-actor — переподчинение по правилу, не вручную
        );
    }
}
