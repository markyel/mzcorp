<?php

namespace App\Services\Supplier;

use App\Enums\RequestActivityType;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Request\RequestActivityService;
use Illuminate\Support\Facades\Log;

/**
 * Формирование и отправка запросов расценки поставщикам из заявки (Фаза 3.2).
 * Подбор поставщиков по позициям (SupplierMatchService) → группировка
 * поставщик×позиции → на каждого поставщика одно письмо (шаблон + список
 * позиций + примечание) из ящика менеджера → регистрация SupplierInquiry
 * (thread_root_id = message_id исходящего, related_request_id = клиентская
 * заявка) + supplier_inquiry_items. Ответ поставщика поймает matchInbound
 * (Фаза 3.3 распарсит офферы). Архитектура — адаптация dispatch из LazyLift
 * (без токен-формы; у нас email-ответы). См. [[suppliers-module]].
 */
class SupplierDispatchService
{
    public function __construct(
        private readonly SupplierMatchService $matcher,
        private readonly EmailDraftService $drafts,
        private readonly OutgoingMailSender $sender,
        private readonly RequestActivityService $activity,
    ) {
    }

    /**
     * Предпросмотр рассылки: группировка поставщик→позиции + позиции без
     * поставщика. Без записи и отправки.
     *
     * @param  array<int, int>  $itemIds
     * @return array{groups: array<int, array{supplier: \App\Models\Supplier, items: array<int, RequestItem>}>, no_supplier: array<int, RequestItem>}
     */
    public function preview(RequestModel $request, array $itemIds): array
    {
        $items = $this->loadItems($request, $itemIds);

        $groups = [];
        $noSupplier = [];
        foreach ($items as $item) {
            $suppliers = $this->matcher->relevantSuppliers($item);
            if ($suppliers->isEmpty()) {
                $noSupplier[] = $item;
                continue;
            }
            foreach ($suppliers as $s) {
                $groups[$s->id] ??= ['supplier' => $s, 'items' => []];
                $groups[$s->id]['items'][] = $item;
            }
        }

        return ['groups' => array_values($groups), 'no_supplier' => $noSupplier];
    }

    /**
     * Разослать RFQ ВЫБРАННЫМ поставщикам. Каждый получает письмо с теми
     * позициями (из активных / itemIds), которые покрывает его матрица.
     * Отправляет РЕАЛЬНЫЕ письма — только по явному действию оператора.
     *
     * @param  array<int, int>  $supplierIds  кому слать (id из preview groups)
     * @param  array<int, int>  $itemIds      ограничение позиций ([] = все активные)
     * @return array{sent: int, failed: int, no_supplier: int, suppliers: array<int, string>}
     */
    public function dispatch(RequestModel $request, array $supplierIds, array $itemIds, ?string $note, User $by): array
    {
        $preview = $this->preview($request, $itemIds);
        $sent = 0;
        $failed = 0;
        $suppliers = [];
        $selected = array_flip(array_map('intval', $supplierIds));

        foreach ($preview['groups'] as $group) {
            $supplier = $group['supplier'];
            if (! isset($selected[$supplier->id])) {
                continue;
            }
            $items = $group['items'];
            if (trim((string) $supplier->email) === '') {
                $failed++;
                continue;
            }

            try {
                $draft = $this->drafts->createCompose($request, $by);

                $subject = 'Запрос расценки — [' . $request->internal_code . ']';
                $bodyHtml = view('emails.supplier-rfq', [
                    'request' => $request,
                    'supplier' => $supplier,
                    'items' => $items,
                    'note' => trim((string) $note),
                ])->render();

                $this->drafts->update($draft, [
                    'to_recipients' => [['email' => $supplier->email, 'name' => $supplier->name ?: '']],
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_plain' => $this->plainBody($request, $items, (string) $note),
                ]);

                $result = $this->sender->sendDraft($draft->id);
                if (! ($result['success'] ?? false)) {
                    $failed++;
                    Log::warning('SupplierDispatch: send failed', [
                        'request_id' => $request->id,
                        'supplier_id' => $supplier->id,
                        'error' => $result['error'] ?? 'unknown',
                    ]);
                    continue;
                }

                $sentMsg = $result['draft'];
                $inquiry = SupplierInquiry::create([
                    'supplier_email' => mb_strtolower(trim((string) $supplier->email)),
                    'supplier_name' => $supplier->name ?: null,
                    'subject' => $subject,
                    'thread_root_id' => $sentMsg->message_id ?: null,
                    'related_request_id' => $request->id,
                    'status' => 'open',
                    'created_by_user_id' => $by->id,
                ]);
                foreach ($items as $item) {
                    SupplierInquiryItem::create([
                        'supplier_inquiry_id' => $inquiry->id,
                        'request_item_id' => $item->id,
                        'item_name' => $item->parsed_name,
                        'status' => 'pending',
                    ]);
                }
                // Исходящее RFQ — это переписка с поставщиком, не клиентский
                // тред: убираем из треда заявки, прицепляем к inquiry.
                $sentMsg->forceFill([
                    'supplier_inquiry_id' => $inquiry->id,
                    'related_request_id' => null,
                ])->save();

                $sent++;
                $suppliers[] = (string) ($supplier->name ?: $supplier->email);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('SupplierDispatch: exception', [
                    'request_id' => $request->id,
                    'supplier_id' => $supplier->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent > 0) {
            // «Запрос поставщику» — событие гасит attention (ход за поставщиком);
            // когда поставщик ответит, onSupplierReplied поднимет флаг.
            try {
                $this->activity->touch($request->fresh() ?? $request, RequestActivityType::SupplierInquirySent);
            } catch (\Throwable) {
                // не критично для отправки
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'no_supplier' => count($preview['no_supplier']),
            'suppliers' => $suppliers,
        ];
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return \Illuminate\Support\Collection<int, RequestItem>
     */
    private function loadItems(RequestModel $request, array $itemIds): \Illuminate\Support\Collection
    {
        return RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->when($itemIds !== [], fn ($q) => $q->whereIn('id', $itemIds))
            ->with(['brand:id,name', 'kbCategory:id,name,synonyms', 'catalogItem:id,brand,equipment_category_id', 'catalogItem.equipmentCategory:id,name,synonyms'])
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  iterable<int, RequestItem>  $items
     */
    private function plainBody(RequestModel $request, iterable $items, string $note): string
    {
        $lines = ['Здравствуйте!', '', 'Просим дать цену, наличие и срок поставки на позиции:', ''];
        $n = 1;
        foreach ($items as $it) {
            $parts = array_filter([
                $it->parsed_name,
                $it->parsed_brand,
                $it->parsed_article,
                $it->parsed_qty ? trim($it->parsed_qty . ' ' . ($it->parsed_unit ?: 'шт.')) : null,
            ]);
            $lines[] = ($n++) . '. ' . implode(' · ', $parts);
        }
        if (trim($note) !== '') {
            $lines[] = '';
            $lines[] = $note;
        }
        $lines[] = '';
        $lines[] = 'Заявка № ' . $request->internal_code;

        return implode("\n", $lines);
    }
}
