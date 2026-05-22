<?php

namespace App\Services\DocumentDetector;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\AiDecision;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth для жизненного цикла AiDecision
 * (Foundation §7.3 audit + validation framework).
 *
 * - recordSuggestion: создаёт `suggested` запись + дописывает в
 *   `email_messages.detected_artifacts` (для backward-compat / диагностики).
 * - apply: оператор подтвердил suggestion → дёргает RequestStateService
 *   и помечает status=manually_confirmed / auto_applied / failed.
 * - override: оператор выбрал ДРУГОЙ статус, чем предложил AI.
 * - dismiss: оператор закрыл prompt без действия.
 *
 * Foundation §7.3: Phase 4 стартует в suggestion-mode. Auto-mode будет
 * включаться РОПом per detector_type через Settings (Commit 4).
 */
class AiDecisionService
{
    public function __construct(
        private readonly RequestStateService $stateService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload  signals / extracted_date / cited_phrase / …
     */
    public function recordSuggestion(
        DetectorType $type,
        Request $request,
        EmailMessage $message,
        float $confidence,
        array $payload = [],
    ): AiDecision {
        // Идемпотентность: повторный анализ одного письма не должен плодить
        // дубликатов suggestion'ов. Если есть уже suggested-запись с тем же
        // (email_message_id, detector_type) — возвращаем её.
        $existing = AiDecision::query()
            ->where('email_message_id', $message->id)
            ->where('detector_type', $type->value)
            ->where('status', AiDecisionStatus::Suggested->value)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $decision = AiDecision::create([
            'detector_type' => $type->value,
            'status' => AiDecisionStatus::Suggested->value,
            'request_id' => $request->id,
            'email_message_id' => $message->id,
            'confidence' => $confidence,
            'payload' => $payload,
        ]);

        // Foundation §7.3: auto-mode проверка — если type включён в auto и
        // confidence >= threshold, применяем сразу без UI-подтверждения.
        // Иначе остаётся suggested — оператор увидит плашку.
        if ($this->shouldAutoApply($type, $confidence)) {
            $decision = $this->apply($decision, null, ['auto' => true]);
        }

        // Foundation §7.3: дублируем в `email_messages.detected_artifacts`
        // (jsonb массив) для backward-compatibility и быстрой выгрузки.
        $existingArtifacts = is_array($message->detected_artifacts ?? null)
            ? $message->detected_artifacts
            : [];
        $existingArtifacts[] = [
            'decision_id' => $decision->id,
            'type' => $type->value,
            'confidence' => $confidence,
            'detected_at' => now()->toIso8601String(),
            'signals' => $payload['signals'] ?? null,
        ];
        $message->forceFill(['detected_artifacts' => $existingArtifacts])->save();

        Log::info('AiDecisionService: suggestion recorded', [
            'decision_id' => $decision->id,
            'type' => $type->value,
            'request_id' => $request->id,
            'email_message_id' => $message->id,
            'confidence' => $confidence,
        ]);

        return $decision;
    }

    /**
     * Применить AI-suggestion: перевести Request в target-статус.
     *
     * @param  array<string, mixed>  $extra  override_to_status / closed_lost_* /
     *                                       confirmedByUser flag.
     */
    public function apply(AiDecision $decision, ?User $author, array $extra = []): AiDecision
    {
        if ($decision->status->isFinal()) {
            return $decision; // идемпотентность
        }

        $type = $decision->detector_type;
        $target = $type->targetStatus();
        if ($target === null) {
            // например inbound_unclear — нечего применять, mark dismissed.
            return $this->dismiss($decision);
        }

        $request = $decision->request()->first();
        if ($request === null) {
            $decision->update(['status' => AiDecisionStatus::Failed->value]);

            return $decision;
        }

        // Соберём context для transitionTo. Для decline pre-filled из payload.
        $context = [
            'event' => isset($extra['auto']) && $extra['auto'] ? 'ai_auto_apply' : 'ai_manual_confirm',
            'comment' => 'AI detector: ' . $type->label(),
            'payload' => [
                'ai_decision_id' => $decision->id,
                'detector_type' => $type->value,
                'confidence' => $decision->confidence,
            ],
        ];

        if ($target === RequestStatus::ClosedLost) {
            $payload = is_array($decision->payload) ? $decision->payload : [];
            // Reason — может быть пробросан из extra (CloseLostDialog override),
            // или из AI-suggested reason'а.
            $reason = $extra['closed_lost_reason']
                ?? $payload['suggested_closed_lost_reason']
                ?? 'manual_other';
            $context['closed_lost_reason'] = $reason;
            $context['closed_lost_comment'] = $extra['closed_lost_comment']
                ?? $payload['cited_phrase']
                ?? null;
            $context['closed_lost_quote'] = $payload['cited_phrase'] ?? null;
            $context['closed_lost_source_message_id'] = $decision->email_message_id;
        }

        // Inbound postponed: дату клиента из payload пробрасываем
        // в state_change.payload — AttentionService::postponedUntilFor()
        // прочитает её для дедлайна возврата.
        if ($target === RequestStatus::PostponedUntil) {
            $payload = is_array($decision->payload) ? $decision->payload : [];
            $extracted = $payload['suggested_resume_date'] ?? null;
            if (is_string($extracted) && $extracted !== '') {
                $context['payload']['postponed_until'] = $extracted;
            }
        }

        // Auto-mode: author=null. RequestStateService::ensureCanTransition
        // в этом случае бьёт abort(403) с пустым message — поэтому передаём
        // systemTransition=true для system-actor'а (audit by_user_id=null).
        // Manual-confirm путь (author не null) — permission check проходит штатно.
        $isAuto = isset($extra['auto']) && $extra['auto'];
        $isSystemActor = $isAuto || $author === null;

        DB::transaction(function () use ($decision, $request, $target, $author, $context, $isAuto, $isSystemActor) {
            try {
                $this->stateService->transitionTo(
                    $request,
                    $target,
                    $author,
                    $context,
                    systemTransition: $isSystemActor,
                );
                $decision->update([
                    'status' => $isAuto
                        ? AiDecisionStatus::AutoApplied->value
                        : AiDecisionStatus::ManuallyConfirmed->value,
                    'applied_at' => now(),
                    'applied_by_user_id' => $author?->id,
                ]);
            } catch (\DomainException $e) {
                // State machine отказал — например заявка уже terminal
                // (closed_lost / closed_won), или transitionTo требует
                // данных, которых нет в auto-payload (closed_lost_reason).
                // Это НЕ technical failure — suggestion стал неактуален.
                // Помечаем Dismissed, чтобы Failed оставалось только для
                // непредвиденных багов (легче триажить failed_jobs).
                Log::info('AiDecisionService: apply dismissed by state machine', [
                    'decision_id' => $decision->id,
                    'target' => $target->value,
                    'reason' => $e->getMessage(),
                ]);
                $decision->update([
                    'status' => AiDecisionStatus::Dismissed->value,
                    'applied_at' => now(),
                    'payload' => array_merge(
                        is_array($decision->payload) ? $decision->payload : [],
                        ['dismiss_reason' => $e->getMessage()],
                    ),
                ]);
            } catch (\Throwable $e) {
                Log::warning('AiDecisionService: apply failed — transitionTo threw', [
                    'decision_id' => $decision->id,
                    'target' => $target->value,
                    'error' => $e->getMessage(),
                    'error_class' => $e::class,
                ]);
                $decision->update([
                    'status' => AiDecisionStatus::Failed->value,
                    'payload' => array_merge(
                        is_array($decision->payload) ? $decision->payload : [],
                        [
                            'apply_error' => $e->getMessage(),
                            'apply_error_class' => $e::class,
                        ],
                    ),
                ]);
            }
        });

        return $decision->fresh();
    }

    /**
     * Оператор выбрал другой целевой статус (override).
     * Пишем `manually_overridden` + поле override_to_status + дёргаем переход
     * в новый статус.
     */
    public function override(
        AiDecision $decision,
        RequestStatus $newStatus,
        User $author,
        array $extra = [],
    ): AiDecision {
        if ($decision->status->isFinal()) {
            return $decision;
        }

        $request = $decision->request()->first();
        if ($request === null) {
            $decision->update(['status' => AiDecisionStatus::Failed->value]);

            return $decision;
        }

        DB::transaction(function () use ($decision, $request, $newStatus, $author, $extra) {
            try {
                $this->stateService->transitionTo($request, $newStatus, $author, array_merge([
                    'event' => 'ai_override',
                    'comment' => 'AI: ' . $decision->detector_type->label() . ' → override на ' . $newStatus->label(),
                    'payload' => [
                        'ai_decision_id' => $decision->id,
                        'detector_type' => $decision->detector_type->value,
                        'overridden_target' => $decision->detector_type->targetStatus()?->value,
                    ],
                ], $extra));
                $decision->update([
                    'status' => AiDecisionStatus::ManuallyOverridden->value,
                    'applied_at' => now(),
                    'applied_by_user_id' => $author->id,
                    'override_to_status' => $newStatus->value,
                ]);
            } catch (\Throwable $e) {
                Log::warning('AiDecisionService: override failed', [
                    'decision_id' => $decision->id,
                    'error' => $e->getMessage(),
                ]);
                $decision->update(['status' => AiDecisionStatus::Failed->value]);
            }
        });

        return $decision->fresh();
    }

    public function dismiss(AiDecision $decision, ?User $author = null): AiDecision
    {
        if ($decision->status->isFinal()) {
            return $decision;
        }
        $decision->update([
            'status' => AiDecisionStatus::Dismissed->value,
            'applied_at' => now(),
            'applied_by_user_id' => $author?->id,
        ]);

        return $decision;
    }

    /**
     * Foundation §7.3: auto-mode gate.
     * РОП включает auto-mode per type в Settings ПОСЛЕ накопления выборки
     * и проверки error rate. Если включён И confidence >= порога —
     * recordSuggestion дёргает apply сразу.
     */
    private function shouldAutoApply(DetectorType $type, float $confidence): bool
    {
        $autoEnabled = (bool) app_setting('detector.auto_mode.' . $type->value, false);
        if (! $autoEnabled) {
            return false;
        }
        $threshold = (float) app_setting('detector.confidence_threshold', 0.85);

        return $confidence >= $threshold;
    }
}
