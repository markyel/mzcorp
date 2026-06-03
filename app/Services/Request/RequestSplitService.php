<?php

namespace App\Services\Request;

use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Models\Quotation;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\RequestStateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ручное разъединение заявки (Split / un-merge) — обратная операция к
 * RequestMergeService.
 *
 * Когда linker ошибочно склеил два разных потока переписки одного клиента в
 * одну заявку (исторический баг до scope-гарда по личному ящику, фикс
 * efd5206), admin/РОП/директор выделяет в карточке письма ЧУЖОГО потока + их
 * позиции и выносит в НОВУЮ заявку.
 *
 *   - Выбранные email_messages → новая заявка (related_request_id).
 *   - Выбранные RequestItem'ы → новая заявка (request_id) — переносятся (move),
 *     а не клонируются: они физически уходят из исходной.
 *   - КП (Quotation) и уточнения (ClarificationBatch), отправленные одним из
 *     выносимых писем (sent_email_message_id / sent_message_id ∈ выбранным),
 *     тоже переезжают.
 *   - Новая заявка назначается: auto (round-robin + sticky, в т.ч. Level 0
 *     «личный ящик → его владелец») ИЛИ конкретному менеджеру.
 *   - Аудит в обеих: state_changes `split_into` (исходная) / `split_from` (новая).
 *
 * Провенанс позиция → письмо берётся из request_items.source_email_message_id
 * (заполняется парсером + бэкфилл-командой). UI предвыбирает позиции по нему,
 * но фактический набор itemIds приходит из диалога (гибрид — админ может
 * скорректировать).
 */
class RequestSplitService
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly AssignmentService $assignment,
        private readonly ReassignService $reassign,
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
    ) {
    }

    /**
     * @param  array<int>  $emailIds  письма исходной заявки, выносимые в новую
     * @param  array<int>  $itemIds   позиции исходной заявки, выносимые в новую
     * @param  'auto'|'manager'  $assignMode
     * @return array{new_request_id:int,new_internal_code:string,emails_moved:int,items_moved:int,quotes_moved:int,batches_moved:int,assigned_to:?string}
     */
    public function split(
        Request $source,
        array $emailIds,
        array $itemIds,
        string $assignMode,
        ?int $assignToUserId,
        User $by,
    ): array {
        $emailIds = array_values(array_unique(array_map('intval', $emailIds)));
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        $this->validate($source, $emailIds, $itemIds, $by);

        $stats = ['emails_moved' => 0, 'items_moved' => 0, 'quotes_moved' => 0, 'batches_moved' => 0];

        $newRequest = DB::transaction(function () use ($source, $emailIds, $itemIds, $by, &$stats) {
            // Seed новой заявки — самое раннее из выносимых писем.
            $seed = EmailMessage::query()
                ->whereIn('id', $emailIds)
                ->orderBy('sent_at')
                ->orderBy('id')
                ->first();

            $new = Request::create([
                'internal_code' => $this->codeGenerator->next(),
                'email_message_id' => $seed?->id,
                'status' => RequestStatus::New,
                'client_email' => $source->client_email,
                'client_name' => $source->client_name,
                'subject' => $seed?->subject ?: $source->subject,
            ]);

            // 1. Письма → новая заявка.
            $emailsMoved = EmailMessage::query()
                ->where('related_request_id', $source->id)
                ->whereIn('id', $emailIds)
                ->update(['related_request_id' => $new->id]);

            // 2. Позиции → новая заявка (move). Перенумеровываем position с 1.
            $items = RequestItem::query()
                ->where('request_id', $source->id)
                ->whereIn('id', $itemIds)
                ->orderBy('position')
                ->get();
            $pos = 0;
            foreach ($items as $item) {
                $item->request_id = $new->id;
                $item->position = ++$pos;
                $item->save();
            }
            $itemsMoved = $items->count();

            // 3. КП, отправленные одним из выносимых писем.
            $quotesMoved = Quotation::query()
                ->where('request_id', $source->id)
                ->whereIn('sent_email_message_id', $emailIds)
                ->update(['request_id' => $new->id]);

            // 4. Уточнения (батчи), отправленные одним из выносимых писем.
            $batchesMoved = ClarificationBatch::query()
                ->where('request_id', $source->id)
                ->whereIn('sent_message_id', $emailIds)
                ->update(['request_id' => $new->id]);

            $stats = [
                'emails_moved' => (int) $emailsMoved,
                'items_moved' => (int) $itemsMoved,
                'quotes_moved' => (int) $quotesMoved,
                'batches_moved' => (int) $batchesMoved,
            ];

            // 5. Аудит в обеих заявках.
            RequestStateChange::create([
                'request_id' => $source->id,
                'from_status' => $source->status->value,
                'to_status' => $source->status->value,
                'by_user_id' => $by->id,
                'event' => 'split_into',
                'comment' => sprintf('Часть переписки вынесена в %s', $new->internal_code),
                'payload' => array_merge($stats, [
                    'split_into_id' => $new->id,
                    'split_into_internal_code' => $new->internal_code,
                ]),
            ]);

            RequestStateChange::create([
                'request_id' => $new->id,
                'from_status' => null,
                'to_status' => RequestStatus::New->value,
                'by_user_id' => $by->id,
                'event' => 'split_from',
                'comment' => sprintf('Выделена из %s', $source->internal_code),
                'payload' => array_merge($stats, [
                    'split_from_id' => $source->id,
                    'split_from_internal_code' => $source->internal_code,
                ]),
            ]);

            // 6. Пересчёт исходной — позиции/письма ушли.
            $this->attention->recompute($source);
            $this->activity->touch($source, RequestActivityType::StatusChange);

            return $new;
        });

        // 7. Назначение новой заявки (вне транзакции split — assignment-сервисы
        //    сами оборачивают свою запись в транзакцию и шлют async-jobs).
        $assignedTo = $this->assignNewRequest($newRequest, $assignMode, $assignToUserId, $by);

        Log::info('RequestSplitService: split done', [
            'source_id' => $source->id,
            'new_request_id' => $newRequest->id,
            'by_user_id' => $by->id,
            'emails' => $emailIds,
            'items' => $itemIds,
            'assign_mode' => $assignMode,
            'assigned_to' => $assignedTo,
        ]);

        return [
            'new_request_id' => $newRequest->id,
            'new_internal_code' => $newRequest->internal_code,
            'emails_moved' => $stats['emails_moved'],
            'items_moved' => $stats['items_moved'],
            'quotes_moved' => $stats['quotes_moved'],
            'batches_moved' => $stats['batches_moved'],
            'assigned_to' => $assignedTo,
        ];
    }

    private function assignNewRequest(Request $new, string $assignMode, ?int $assignToUserId, User $by): ?string
    {
        if ($assignMode === 'manager' && $assignToUserId !== null) {
            $manager = User::query()
                ->role(RoleEnum::requestHandlerRoles())
                ->active()
                ->whereKey($assignToUserId)
                ->first();
            if ($manager === null) {
                throw new \DomainException('Менеджер не найден или в архиве.');
            }
            $this->reassign->reassign(
                request: $new,
                newAssignee: $manager,
                reason: 'split: вынесено в отдельную заявку',
                by: $by,
            );

            return $manager->name;
        }

        // auto: sticky (в т.ч. Level 0 «личный ящик → владелец») + round-robin.
        $manager = $this->assignment->autoAssign($new, $by->id);

        return $manager?->name;
    }

    /**
     * @param  array<int>  $emailIds
     * @param  array<int>  $itemIds
     */
    public function validate(Request $source, array $emailIds, array $itemIds, User $by): void
    {
        $allowed = $by->hasAnyRole([
            RoleEnum::Admin->value,
            RoleEnum::Director->value,
            RoleEnum::HeadOfSales->value,
        ]);
        if (! $allowed) {
            throw new \DomainException('Разъединение доступно только администратору, директору или РОПу.');
        }

        if ($source->status->isTerminal()) {
            throw new \DomainException(sprintf(
                'Заявка %s в терминальном статусе (%s) — разъединение недоступно.',
                $source->internal_code,
                $source->status->label(),
            ));
        }

        if (empty($emailIds)) {
            throw new \DomainException('Не выбрано ни одного письма для выноса.');
        }

        // Все выбранные письма принадлежат исходной заявке.
        $ownEmailIds = EmailMessage::query()
            ->where('related_request_id', $source->id)
            ->pluck('id')
            ->all();
        $foreign = array_diff($emailIds, $ownEmailIds);
        if ($foreign !== []) {
            throw new \DomainException('Некоторые письма не принадлежат этой заявке.');
        }

        // Нельзя выносить seed-письмо исходной заявки (её точку отсчёта).
        if ($source->email_message_id !== null && in_array((int) $source->email_message_id, $emailIds, true)) {
            throw new \DomainException('Нельзя вынести исходное (seed) письмо заявки — оно её точка отсчёта.');
        }

        // В исходной должно остаться хотя бы одно письмо.
        if (count($ownEmailIds) <= count($emailIds)) {
            throw new \DomainException('Нельзя вынести все письма — в исходной заявке должно остаться хотя бы одно.');
        }

        // Все выбранные позиции принадлежат исходной заявке.
        if ($itemIds !== []) {
            $ownItemIds = RequestItem::query()
                ->where('request_id', $source->id)
                ->pluck('id')
                ->all();
            if (array_diff($itemIds, $ownItemIds) !== []) {
                throw new \DomainException('Некоторые позиции не принадлежат этой заявке.');
            }
        }
    }
}
