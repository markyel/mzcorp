<?php

namespace App\Services\Supplier;

use App\Enums\RequestActivityType;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Request\RequestActivityService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    /**
     * @param  array<int, string>  $nameOverrides    item_id => название (русская версия)
     * @param  ?string  $greeting  шаблон обращения с плейсхолдером {поставщик}
     * @param  array<int, string>  $nameOverridesEn  item_id => название (английская версия)
     */
    public function dispatch(RequestModel $request, array $supplierIds, array $itemIds, ?string $note, User $by, array $reqAttachmentIds = [], array $extraFiles = [], array $nameOverrides = [], ?string $greeting = null, array $nameOverridesEn = []): array
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

                $lang = in_array($supplier->language, ['ru', 'en'], true) ? $supplier->language : 'ru';
                $rows = $this->itemRows($items, $lang === 'en' ? $nameOverridesEn : $nameOverrides, $lang);
                $personalGreeting = $this->personalGreeting($greeting, $supplier, $lang);
                $subject = $lang === 'en'
                    ? 'Price request — [' . $request->internal_code . ']'
                    : 'Запрос расценки — [' . $request->internal_code . ']';
                $bodyHtml = view('emails.supplier-rfq', [
                    'request' => $request,
                    'supplier' => $supplier,
                    'rows' => $rows,
                    'note' => trim((string) $note),
                    'greeting' => $personalGreeting,
                    'lang' => $lang,
                ])->render();

                $this->drafts->update($draft, [
                    'to_recipients' => [['email' => $supplier->email, 'name' => $supplier->name ?: '']],
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_plain' => $this->plainBody($request, $rows, (string) $note, $personalGreeting, $lang),
                ]);

                // Вложения: файлы заявки + загруженные с диска — до отправки,
                // чтобы попали в MIME.
                $this->attachToDraft($draft->fresh(), $reqAttachmentIds, $extraFiles);

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
            ->with(['brand:id,name', 'kbCategory:id,name,synonyms', 'catalogItem:id,name,name_en,brand,equipment_category_id,is_price_actual', 'catalogItem.equipmentCategory:id,name,synonyms'])
            ->orderBy('position')
            ->get();
    }

    /**
     * Отображаемые данные позиции для письма: название берём из КАТАЛОГА, если
     * позиция сматчена (M-SKU), иначе формулировку клиента; OEM — артикул.
     *
     * @param  iterable<int, RequestItem>  $items
     * @param  array<int, string>  $nameOverrides  item_id => отредактированное менеджером название (для этого языка)
     * @param  string  $lang  ru|en — определяет дефолтное название (en: каталожный name_en)
     * @return array<int, array{name:string, oem:?string, brand:?string, qty:?string}>
     */
    public function itemRows(iterable $items, array $nameOverrides = [], string $lang = 'ru'): array
    {
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                'name' => $this->itemName($it, $nameOverrides, $lang),
                'oem' => $it->parsed_article ?: null,
                'brand' => ($it->brand?->name ?: $it->parsed_brand) ?: null,
                'qty' => $it->parsed_qty ? trim($it->parsed_qty . ' ' . ($it->parsed_unit ?: 'шт.')) : null,
            ];
        }

        return $rows;
    }

    /**
     * Название позиции для письма: правка менеджера (если есть для языка)
     * приоритетна; иначе дефолт — для EN каталожный name_en (или name), иначе
     * формулировка клиента; для RU каталожное name, иначе клиент.
     *
     * @param  array<int, string>  $nameOverrides
     */
    public function itemName(RequestItem $it, array $nameOverrides = [], string $lang = 'ru'): string
    {
        $override = isset($nameOverrides[$it->id]) ? trim((string) $nameOverrides[$it->id]) : '';
        if ($override !== '') {
            return $override;
        }
        $catalog = $it->catalog_item_id ? $it->catalogItem : null;
        if ($lang === 'en') {
            $name = $catalog ? ($catalog->name_en ?: $catalog->name ?: $it->parsed_name) : $it->parsed_name;
        } else {
            $name = ($catalog?->name) ?: $it->parsed_name;
        }

        return (string) ($name ?: '—');
    }

    /**
     * Персональное обращение: подставляет имя поставщика в плейсхолдер
     * {поставщик}. Пустой шаблон → дефолт. Нет имени → «коллеги».
     */
    public function personalGreeting(?string $template, \App\Models\Supplier $supplier, string $lang = 'ru'): string
    {
        $template = trim((string) $template);
        if ($lang === 'en') {
            // RU-шаблон менеджера на EN не используем — берём EN-дефолт.
            $template = 'Hello {поставщик},';
            $name = trim((string) ($supplier->name ?: '')) ?: 'colleagues';
        } else {
            if ($template === '') {
                $template = 'Здравствуйте, {поставщик}!';
            }
            $name = trim((string) ($supplier->name ?: '')) ?: 'коллеги';
        }

        return str_replace(['{поставщик}', '{supplier}'], $name, $template);
    }

    /**
     * Прицепить к черновику вложения: копии файлов заявки (по id) + уже
     * сохранённые на local файлы с диска ([{path,name,mime,size}]). Копируем
     * по отдельному файлу на каждый черновик (изоляция при удалении).
     *
     * @param  array<int, int>  $reqAttachmentIds
     * @param  array<int, array{path:string,name:string,mime:string,size:int}>  $extraFiles
     */
    private function attachToDraft(EmailMessage $draft, array $reqAttachmentIds, array $extraFiles): void
    {
        $copy = function (string $disk, string $srcPath, string $filename, ?string $mime, int $size) use ($draft) {
            try {
                if (! Storage::disk($disk)->exists($srcPath)) {
                    return;
                }
                $newPath = sprintf('mail/%d/drafts/%d/%s', $draft->mailbox_id ?? 0, $draft->id, Str::random(8) . '_' . $this->safeName($filename));
                Storage::disk('local')->put($newPath, Storage::disk($disk)->get($srcPath));
                EmailAttachment::create([
                    'email_message_id' => $draft->id,
                    'filename' => mb_substr($filename, 0, 255),
                    'mime_type' => $mime ?: 'application/octet-stream',
                    'size_bytes' => $size,
                    'content_id' => null,
                    'file_path' => $newPath,
                    'disk' => 'local',
                    'is_inline' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('SupplierDispatch: attach copy failed', ['draft_id' => $draft->id, 'file' => $filename, 'error' => $e->getMessage()]);
            }
        };

        foreach (EmailAttachment::query()->whereIn('id', $reqAttachmentIds)->get() as $a) {
            $copy((string) ($a->disk ?: 'local'), (string) $a->file_path, (string) $a->filename, $a->mime_type, (int) $a->size_bytes);
        }
        foreach ($extraFiles as $f) {
            if (! empty($f['path'])) {
                $copy('local', (string) $f['path'], (string) ($f['name'] ?? 'file'), $f['mime'] ?? null, (int) ($f['size'] ?? 0));
            }
        }
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $name) ?? 'file';

        return mb_substr(trim($name), 0, 120) ?: 'file';
    }

    /**
     * @param  array<int, array{name:string, oem:?string, brand:?string, qty:?string}>  $rows
     */
    private function plainBody(RequestModel $request, array $rows, string $note, string $greeting = 'Здравствуйте!', string $lang = 'ru'): string
    {
        $en = $lang === 'en';
        $intro = $en ? 'Please quote price, availability and lead time for the following items:' : 'Просим дать цену, наличие и срок поставки на позиции:';
        $footer = $en ? 'Request No. ' : 'Заявка № ';

        $lines = [$greeting !== '' ? $greeting : ($en ? 'Hello,' : 'Здравствуйте!'), '', $intro, ''];
        $n = 1;
        foreach ($rows as $r) {
            $parts = array_filter([$r['name'], $r['oem'], $r['brand'], $r['qty']]);
            $lines[] = ($n++) . '. ' . implode(' · ', $parts);
        }
        if (trim($note) !== '') {
            $lines[] = '';
            $lines[] = $note;
        }
        $lines[] = '';
        $lines[] = $footer . $request->internal_code;

        return implode("\n", $lines);
    }
}
