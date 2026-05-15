<?php

namespace App\Services\Request;

use App\Enums\ClosedLostReason;
use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\RequestStateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Слияние заявок-дубликатов (Phase merge).
 *
 * Когда менеджер обнаруживает, что у одного клиента две заявки про одно
 * и то же (например, рассылка партнёра на 2+ наших адресов создала
 * параллельные Request — кейс LZ-REQ-1208), он сливает loser в winner.
 *
 *   - Все email_messages → winner (треды объединяются).
 *   - RequestItem'ы → winner с дедупликацией по (parsed_article + parsed_name)
 *     normalized: повторы пропускаются, уникальные копируются.
 *   - ClarificationBatch'и → winner (переписка по уточнениям сохраняется).
 *   - Loser закрывается closed_lost с reason=duplicate, comment=«Слита с M-NNNN»,
 *     поле merged_into_id указывает на winner, merged_at = now.
 *   - state_changes в обеих заявках для audit (`merge_from` / `merged_into`).
 *
 * Сливаем ТОЛЬКО когда обе заявки active (Foundation 2026-05-22 решение):
 *   - active = не Paused/ClosedWon/ClosedLost/Pending/Paid
 *   - оба client_email совпадают (case-insensitive)
 *   - $by — owner/acting winner-а ИЛИ privileged
 *   - $by — owner/acting loser-а ИЛИ privileged
 *
 * Не перемещаются: subject, client_email, assigned_user_id (winner сохраняет
 * свой). История state_changes loser-а остаётся в loser-е (как audit).
 */
class RequestMergeService
{
    public function __construct(
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
    ) {
    }

    private const SILENT_STATUSES = [
        RequestStatus::Paused,
        RequestStatus::ClosedWon,
        RequestStatus::ClosedLost,
        RequestStatus::Pending,
        RequestStatus::Paid,
    ];

    /**
     * Provee a slied loser у winner. Возвращает stats.
     *
     * @return array{items_added: int, items_skipped: int, emails_moved: int, batches_moved: int}
     */
    public function merge(Request $winner, Request $loser, User $by): array
    {
        $this->validate($winner, $loser, $by);

        return DB::transaction(function () use ($winner, $loser, $by) {
            // 1. Перенос email_messages.
            $emailsMoved = EmailMessage::query()
                ->where('related_request_id', $loser->id)
                ->update(['related_request_id' => $winner->id]);

            // 2. Перенос items с дедупликацией.
            [$itemsAdded, $itemsSkipped] = $this->mergeItems($winner, $loser);

            // 3. Перенос ClarificationBatch'ей.
            $batchesMoved = ClarificationBatch::query()
                ->where('request_id', $loser->id)
                ->update(['request_id' => $winner->id]);

            // 4. Audit в обоих.
            $stats = [
                'items_added' => $itemsAdded,
                'items_skipped' => $itemsSkipped,
                'emails_moved' => $emailsMoved,
                'batches_moved' => $batchesMoved,
            ];

            RequestStateChange::create([
                'request_id' => $winner->id,
                'from_status' => $winner->status->value,
                'to_status' => $winner->status->value,
                'by_user_id' => $by->id,
                'event' => 'merge_from',
                'comment' => sprintf('Объединена заявка %s', $loser->internal_code),
                'payload' => array_merge($stats, [
                    'merged_from_id' => $loser->id,
                    'merged_from_internal_code' => $loser->internal_code,
                ]),
            ]);

            RequestStateChange::create([
                'request_id' => $loser->id,
                'from_status' => $loser->status->value,
                'to_status' => RequestStatus::ClosedLost->value,
                'by_user_id' => $by->id,
                'event' => 'merged_into',
                'comment' => sprintf('Объединена с %s', $winner->internal_code),
                'payload' => array_merge($stats, [
                    'merged_into_id' => $winner->id,
                    'merged_into_internal_code' => $winner->internal_code,
                    'closed_lost_reason' => ClosedLostReason::Duplicate->value,
                ]),
            ]);

            // 5. Loser: closed_lost + merged_into_id.
            $loser->status = RequestStatus::ClosedLost;
            $loser->closed_at = now();
            $loser->closed_lost_reason = ClosedLostReason::Duplicate->value;
            $loser->closed_lost_comment = sprintf('Объединена с %s', $winner->internal_code);
            $loser->merged_into_id = $winner->id;
            $loser->merged_at = now();
            $loser->save();
            $this->attention->clear($loser);

            // 6. Winner — поднимаем activity timestamp, тип StatusChange
            // (новости в заявке, но reason attention не меняется).
            $this->activity->touch($winner, RequestActivityType::StatusChange);

            Log::info('RequestMergeService: merged', [
                'winner_id' => $winner->id,
                'loser_id' => $loser->id,
                'by_user_id' => $by->id,
                'stats' => $stats,
            ]);

            return $stats;
        });
    }

    /**
     * Превью слияния — что будет перенесено. Не меняет БД.
     *
     * @return array{items_to_add: int, items_to_skip: int, emails_to_move: int, batches_to_move: int, conflicts: array<int, string>}
     */
    public function preview(Request $winner, Request $loser): array
    {
        $loserItems = RequestItem::query()
            ->where('request_id', $loser->id)
            ->where('is_active', true)
            ->get(['id', 'parsed_article', 'parsed_name']);

        $winnerKeys = $this->buildItemKeySet($winner);
        $toAdd = 0;
        $toSkip = 0;
        foreach ($loserItems as $it) {
            $key = $this->itemKey($it->parsed_article, $it->parsed_name);
            if ($key === '' || ! isset($winnerKeys[$key])) {
                $toAdd++;
            } else {
                $toSkip++;
            }
        }

        $emailsCount = EmailMessage::query()->where('related_request_id', $loser->id)->count();
        $batchesCount = ClarificationBatch::query()->where('request_id', $loser->id)->count();

        return [
            'items_to_add' => $toAdd,
            'items_to_skip' => $toSkip,
            'emails_to_move' => $emailsCount,
            'batches_to_move' => $batchesCount,
            'conflicts' => $this->checkConflicts($winner, $loser),
        ];
    }

    /**
     * @return array<int, string>  Список нарушенных правил.
     */
    private function checkConflicts(Request $winner, Request $loser): array
    {
        $errors = [];
        if ($winner->id === $loser->id) {
            $errors[] = 'Нельзя слить заявку саму с собой.';
        }
        if ($this->isSilent($winner)) {
            $errors[] = sprintf('Winner %s в статусе %s — не active.', $winner->internal_code, $winner->status->label());
        }
        if ($this->isSilent($loser)) {
            $errors[] = sprintf('Loser %s в статусе %s — не active.', $loser->internal_code, $loser->status->label());
        }
        $w = mb_strtolower(trim((string) $winner->client_email));
        $l = mb_strtolower(trim((string) $loser->client_email));
        if ($w === '' || $l === '' || $w !== $l) {
            $errors[] = 'Слияние доступно только для заявок одного клиента (client_email должны совпадать).';
        }

        return $errors;
    }

    private function validate(Request $winner, Request $loser, User $by): void
    {
        $errors = $this->checkConflicts($winner, $loser);
        if ($errors !== []) {
            throw new \DomainException(implode(' ', $errors));
        }

        $privileged = $by->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
        ]);
        if ($privileged) {
            return;
        }
        if ($by->hasRole(RoleEnum::Secretary->value)) {
            throw new \DomainException('Секретарь только просматривает заявки.');
        }
        if (! $winner->isAccessibleBy($by)) {
            throw new \DomainException(sprintf('Winner %s не доступна вам.', $winner->internal_code));
        }
        if (! $loser->isAccessibleBy($by)) {
            throw new \DomainException(sprintf('Loser %s не доступна вам.', $loser->internal_code));
        }
    }

    private function isSilent(Request $r): bool
    {
        return in_array($r->status, self::SILENT_STATUSES, true);
    }

    /**
     * Копирует уникальные item'ы loser-а в winner. Дедупликация по
     * normalized (article, name).
     *
     * @return array{0: int, 1: int}  [added, skipped]
     */
    private function mergeItems(Request $winner, Request $loser): array
    {
        $winnerKeys = $this->buildItemKeySet($winner);
        $maxPosition = (int) (RequestItem::query()
            ->where('request_id', $winner->id)
            ->where('is_active', true)
            ->max('position') ?? 0);

        $loserItems = RequestItem::query()
            ->where('request_id', $loser->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        $added = 0;
        $skipped = 0;

        foreach ($loserItems as $src) {
            $key = $this->itemKey($src->parsed_article, $src->parsed_name);
            if ($key !== '' && isset($winnerKeys[$key])) {
                $skipped++;
                continue;
            }

            // Replicate как новую строку у winner-а. Используем replicate(),
            // потом подменяем request_id и position. RequestItemObserver
            // дёрнет touch на winner — это норм.
            $copy = $src->replicate();
            $copy->request_id = $winner->id;
            $copy->position = ++$maxPosition;
            $copy->save();

            // Помечаем оригинал у loser как inactive — он остаётся в БД
            // (исторический след), но не показывается в UI loser-а.
            $src->is_active = false;
            $src->save();

            $winnerKeys[$key] = true;
            $added++;
        }

        return [$added, $skipped];
    }

    /**
     * @return array<string, true>
     */
    private function buildItemKeySet(Request $request): array
    {
        $items = RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->get(['parsed_article', 'parsed_name']);

        $keys = [];
        foreach ($items as $it) {
            $k = $this->itemKey($it->parsed_article, $it->parsed_name);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }

        return $keys;
    }

    private function itemKey(?string $article, ?string $name): string
    {
        $a = mb_strtolower(trim((string) $article));
        $n = mb_strtolower(trim((string) $name));

        return ($a === '' && $n === '') ? '' : $a . '|' . $n;
    }
}
