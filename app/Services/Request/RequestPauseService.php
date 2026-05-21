<?php

namespace App\Services\Request;

use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pause / resume заявки (Phase 1.10, Foundation §5.4).
 *
 * Pause-mehanic — мета-статус: заявка временно «заморожена» с явной датой
 * автоматического возврата к предыдущему статусу. На pause:
 *  - request.status = Paused
 *  - request.paused_from_status = (предыдущий status)
 *  - request.paused_until = (дата возврата)
 *  - request.paused_reason = (текст оператора)
 *
 * Resume (через cron или вручную):
 *  - request.status = paused_from_status
 *  - paused_* поля очищаются
 *  - audit-запись в request_state_changes
 *
 * Cap-проверка: paused_until ≤ today + max_pause_days (config; дефолт 21).
 */
class RequestPauseService
{
    public function __construct(
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
    ) {
    }

    public function pauseUntil(
        Request $request,
        Carbon $until,
        string $reason,
        User $author,
    ): Request {
        $this->ensureCanPause($request, $author);

        if (! $request->status->canBePaused()) {
            throw new \DomainException(sprintf(
                'Из статуса «%s» нельзя ставить заявку на паузу.',
                $request->status->label()
            ));
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('Укажите причину паузы (минимум 3 символа).');
        }

        $maxDays = (int) config('services.requests.max_pause_days', 21);
        $maxAllowed = now()->startOfDay()->addDays($maxDays);
        if ($until->greaterThan($maxAllowed)) {
            throw new \DomainException(sprintf(
                'Максимальная длительность паузы — %d дн. (до %s).',
                $maxDays,
                $maxAllowed->format('d.m.Y'),
            ));
        }
        if ($until->lessThanOrEqualTo(now())) {
            throw new \DomainException('Дата возврата из паузы должна быть в будущем.');
        }

        $fromStatus = $request->status;

        DB::transaction(function () use ($request, $until, $reason, $author, $fromStatus) {
            $request->paused_from_status = $fromStatus->value;
            $request->paused_until = $until;
            $request->paused_reason = $reason;
            $request->status = RequestStatus::Paused;
            $request->save();

            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $fromStatus->value,
                'to_status' => RequestStatus::Paused->value,
                'by_user_id' => $author->id,
                'event' => 'manual',
                'comment' => $reason,
                'payload' => [
                    'paused_until' => $until->toIso8601String(),
                    'paused_from_status' => $fromStatus->value,
                ],
            ]);

            // Phase 1.11: paused — silent статус, attention снимаем.
            $this->attention->clear($request);

            $this->activity->touch($request, \App\Enums\RequestActivityType::Paused);
        });

        Log::info('RequestPauseService: paused', [
            'request_id' => $request->id,
            'until' => $until->toIso8601String(),
            'from_status' => $fromStatus->value,
            'by' => $author->id,
        ]);

        return $request;
    }

    /**
     * Снять с паузы. Возвращает в paused_from_status, или Assigned если
     * стартовый статус потерян (renamed enum / пустое поле).
     */
    public function resume(Request $request, ?User $author = null, string $event = 'manual'): Request
    {
        if ($author !== null) {
            $this->ensureCanPause($request, $author);
        }
        if ($request->status !== RequestStatus::Paused) {
            return $request; // идемпотентность
        }

        $fromPaused = $request->paused_from_status
            ? RequestStatus::tryFrom($request->paused_from_status)
            : null;
        $target = $fromPaused ?? RequestStatus::Assigned;

        DB::transaction(function () use ($request, $target, $author, $event) {
            $previousReason = $request->paused_reason;
            $previousUntil = $request->paused_until;

            $request->status = $target;
            $request->paused_until = null;
            $request->paused_from_status = null;
            $request->paused_reason = null;
            $request->save();

            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => RequestStatus::Paused->value,
                'to_status' => $target->value,
                'by_user_id' => $author?->id,
                'event' => $event,
                'comment' => $previousReason,
                'payload' => [
                    'paused_until_was' => $previousUntil?->toIso8601String(),
                ],
            ]);

            // Phase 1.11 (Foundation §5.4): после resume заявка тут же
            // должна попасть в фокус — явный attention_required_at = now()
            // с reason=postponed_resume. Перезапишет обычный compute()
            // (он бы дал нормальный дедлайн от now на ~24ч).
            $request->forceFill([
                'attention_required_at' => now(),
                'attention_reason' => \App\Enums\AttentionReason::PostponedResume->value,
                'attention_level' => 1,
            ])->save();

            $this->activity->touch($request, \App\Enums\RequestActivityType::Resumed);
        });

        Log::info('RequestPauseService: resumed', [
            'request_id' => $request->id,
            'to' => $target->value,
            'by' => $author?->id,
            'event' => $event,
        ]);

        return $request;
    }

    /**
     * Для cron `requests:resume-paused`. Снимает с паузы все заявки,
     * чьи paused_until <= now().
     */
    public function applyDuePauses(): int
    {
        $count = 0;
        Request::query()
            ->where('status', RequestStatus::Paused->value)
            ->whereNotNull('paused_until')
            ->where('paused_until', '<=', now())
            ->chunkById(100, function ($requests) use (&$count) {
                foreach ($requests as $req) {
                    $this->resume($req, author: null, event: 'auto_resume_pause');
                    $count++;
                }
            });

        return $count;
    }

    private function ensureCanPause(Request $request, User $author): void
    {
        $privileged = $author->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
            RoleEnum::Admin->value,
        ]);
        if ($privileged) {
            return;
        }
        if ($author->hasRole(RoleEnum::Secretary->value)) {
            abort(403, 'Секретарь только просматривает заявки.');
        }
        if ($request->assigned_user_id === $author->id) {
            return;
        }
        // Foundation Фаза 2: acting (active delegation) тоже может паузить
        // на время отсутствия оригинального менеджера.
        if ($request->isDelegatedTo($author)) {
            return;
        }
        abort(403, 'Ставить на паузу может только assigned-менеджер или РОП.');
    }
}
