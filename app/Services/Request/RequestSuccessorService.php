<?php

namespace App\Services\Request;

use App\Enums\MailDirection;
use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\RequestItem;
use App\Models\RequestStateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Заявка-наследник по одному письму родителя.
 *
 * Бывает, что по заявке отработали не всё: часть позиций из первого письма
 * клиента ушла в КП, а часть осталась. Родителя при этом уже закрыли — и
 * правильно закрыли, работа по нему сделана. Разъединение (split) тут не
 * подходит: оно ПЕРЕНОСИТ письма и позиции, а история закрытой заявки
 * должна остаться нетронутой.
 *
 * Поэтому наследник — это копия: берём выбранное письмо как основание, копируем
 * позиции, спаршенные именно из него, и связываем с родителем как child. Письмо
 * остаётся в треде родителя: одно письмо может породить сколько угодно
 * наследников, и переклеивать его туда-сюда нельзя.
 *
 * Менеджер — тот же, что у родителя: он вёл переписку и знает, что осталось.
 */
class RequestSuccessorService
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly RequestInheritanceService $inheritance,
        private readonly RequestActivityService $activity,
        private readonly AttentionService $attention,
    ) {}

    /**
     * @throws \DomainException письмо не из этой заявки или исходящее.
     */
    public function createFromEmail(Request $parent, EmailMessage $email, ?User $by): Request
    {
        $direction = $email->direction instanceof MailDirection
            ? $email->direction
            : MailDirection::tryFrom((string) $email->direction);
        if ($direction !== MailDirection::Inbound) {
            throw new \DomainException('Наследника заводим по входящему письму клиента, не по нашему ответу.');
        }
        if ((int) $email->related_request_id !== (int) $parent->id
            && (int) $parent->email_message_id !== (int) $email->id) {
            throw new \DomainException('Это письмо не из переписки заявки '.$parent->internal_code.'.');
        }

        $child = DB::transaction(function () use ($parent, $email, $by) {
            $child = Request::create([
                'internal_code' => $this->codeGenerator->next(),
                'email_message_id' => $email->id,
                'status' => $parent->assigned_user_id ? RequestStatus::Assigned : RequestStatus::New,
                'subject' => $email->subject ?: $parent->subject,
                'client_email' => $parent->client_email,
                'client_name' => $parent->client_name,
                'client_company' => $parent->client_company,
                'organization_id' => $parent->organization_id,
                'assigned_user_id' => $parent->assigned_user_id,
                'assigned_at' => $parent->assigned_user_id ? now() : null,
            ]);

            $copied = $this->copyItems($parent, $child, (int) $email->id);

            if ($parent->assigned_user_id !== null) {
                RequestAssignment::create([
                    'request_id' => $child->id,
                    'user_id' => $parent->assigned_user_id,
                    'by_user_id' => $by?->id,
                    'reason' => 'successor_of:'.$parent->internal_code,
                    'assigned_at' => now(),
                ]);
            }

            // Связь «родитель → наследник». Цепочки сервис не поддерживает:
            // если родитель сам чей-то наследник, заявку всё равно создаём —
            // без связи она хуже видна, но лучше, чем не создать вовсе.
            $linked = true;
            try {
                $this->inheritance->linkChild($parent, $child, $this->mappings($parent, $child), 'manual_successor');
            } catch (\Throwable $e) {
                $linked = false;
                Log::warning('RequestSuccessorService: inheritance link skipped', [
                    'parent_request_id' => $parent->id,
                    'child_request_id' => $child->id,
                    'error' => $e->getMessage(),
                ]);
            }

            RequestStateChange::create([
                'request_id' => $child->id,
                'from_status' => null,
                'to_status' => $child->status->value,
                'by_user_id' => $by?->id,
                'event' => 'created_successor_from_email',
                'comment' => sprintf(
                    'Заявка-наследник %s: основание — письмо от %s, позиций скопировано %d.%s',
                    $parent->internal_code,
                    $email->sent_at?->format('d.m.Y H:i') ?? 'без даты',
                    $copied,
                    $linked ? '' : ' Связь с родителем не проставлена: родитель сам наследник.',
                ),
            ]);

            return $child;
        });

        try {
            $this->activity->touch($child, RequestActivityType::RequestCreated);
            $this->attention->onAssigned($child->fresh());
        } catch (\Throwable $e) {
            // Некритично: заявка создана, подсветка и лента — косметика.
        }

        Log::info('RequestSuccessorService: successor created', [
            'parent_request_id' => $parent->id,
            'child_request_id' => $child->id,
            'email_message_id' => $email->id,
            'by_user_id' => $by?->id,
        ]);

        return $child->fresh();
    }

    /**
     * Копия активных позиций родителя, спаршенных из этого письма.
     *
     * Провенанс есть не у всех заявок (у старых source_email_message_id пуст) —
     * тогда копируем все активные: пусть менеджер уберёт лишнее руками, это
     * быстрее, чем набивать позиции заново.
     *
     * Поля те же, что клонирует RequestInheritanceService::adoptFromParent:
     * каталожный матч и оценку качества переносим (артикул тот же, гонять
     * модель заново незачем), вложение-картинку — нет, она принадлежит письму.
     */
    private function copyItems(Request $parent, Request $child, int $emailId): int
    {
        $query = RequestItem::query()
            ->where('request_id', $parent->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id');

        $items = (clone $query)->where('source_email_message_id', $emailId)->get();
        if ($items->isEmpty()) {
            $items = $query->get();
        }

        $position = 0;
        foreach ($items as $pi) {
            RequestItem::create([
                'request_id' => $child->id,
                'position' => ++$position,
                'source_email_message_id' => $pi->source_email_message_id,
                'parsed_name' => $pi->parsed_name,
                'parsed_brand' => $pi->parsed_brand,
                'parsed_article' => $pi->parsed_article,
                'parsed_qty' => $pi->parsed_qty,
                'parsed_unit' => $pi->parsed_unit,
                'parsed_length' => $pi->parsed_length,
                'parsed_length_unit' => $pi->parsed_length_unit,
                'billing_unit' => $pi->billing_unit,
                'supplier_note' => $pi->supplier_note,
                'data_source' => 'successor_from_parent',
                'status' => 'active',
                'is_active' => true,
                'identification_category_id' => $pi->identification_category_id,
                'manufacturer_brand_id' => $pi->manufacturer_brand_id,
                'equipment_unit_id' => $pi->equipment_unit_id,
                'category' => $pi->category,
                'catalog_item_id' => $pi->catalog_item_id,
                'quality_assessment_status' => $pi->quality_assessment_status,
                'quality_assessment_payload' => $pi->quality_assessment_payload,
                'match_path' => $pi->match_path,
            ]);
        }

        return $items->count();
    }

    /**
     * Маппинг «позиция наследника → позиция родителя» по порядку копирования:
     * позиции скопированы один в один, поэтому сопоставление однозначное.
     *
     * @return array<int, array{child_item_id:int, parent_item_id:int, source:string, confidence:float}>
     */
    private function mappings(Request $parent, Request $child): array
    {
        $childItems = RequestItem::query()->where('request_id', $child->id)->orderBy('position')->get();
        $out = [];

        foreach ($childItems as $ci) {
            $parentItem = RequestItem::query()
                ->where('request_id', $parent->id)
                ->where('is_active', true)
                ->where('parsed_name', $ci->parsed_name)
                ->where(fn ($q) => $q->whereNull('parsed_article')->orWhere('parsed_article', $ci->parsed_article))
                ->orderBy('position')
                ->first();
            if ($parentItem === null) {
                continue;
            }
            $out[] = [
                'child_item_id' => (int) $ci->id,
                'parent_item_id' => (int) $parentItem->id,
                'source' => 'successor_copy',
                'confidence' => 1.0,
            ];
        }

        return $out;
    }
}
