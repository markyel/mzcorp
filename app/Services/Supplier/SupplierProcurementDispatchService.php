<?php

namespace App\Services\Supplier;

use App\Enums\MailDirection;
use App\Models\CatalogItem;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Supplier;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use App\Models\User;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Позиция-центричная рассылка запросов поставщикам из раздела «Снабжение»
 * (Фаза 4B). В отличие от request-центричного SupplierDispatchService, RFQ не
 * привязан к клиентской заявке: SupplierInquiry.related_request_id = null,
 * позиции — catalog_item (M-артикул). Когда цена обновится в каталоге (импорт
 * 1С) — все заблокированные заявки получат сигнал «💰» (PriceRefreshReconciler).
 * Письмо шлём из ящика инициатора (primaryOutboundMailbox) или из shared.
 */
class SupplierProcurementDispatchService
{
    public function __construct(
        private readonly OutgoingMailSender $sender,
        private readonly SupplierDispatchService $base,
    ) {}

    /**
     * @param  array<int, int>  $catalogItemIds
     * @param  array<int, int>  $supplierIds
     * @param  array{names_ru?:array<int,string>, names_en?:array<int,string>, oem?:array<int,string>, qty?:array<int,string>, qty_en?:array<int,string>, greeting_ru?:?string, greeting_en?:?string, intro_ru?:?string, intro_en?:?string, closing_ru?:?string, closing_en?:?string}  $edits
     *                                                                                                                                                                                                                                                                                         правки письма по catalog_item: названия/кол-во по языкам + артикул + обращение/вступление/закрытие по языкам
     * @return array{sent:int, failed:int, skipped:int, suppliers:array<int,string>, error:?string}
     */
    public function dispatch(array $catalogItemIds, array $supplierIds, ?string $note, User $by, array $edits = []): array
    {
        $zero = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'suppliers' => [], 'error' => null];

        $items = CatalogItem::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $catalogItemIds))))
            ->get(['id', 'sku', 'name', 'name_en', 'brand', 'brand_article']);
        if ($items->isEmpty()) {
            return $zero;
        }

        $mailbox = $this->resolveMailbox($by);
        if ($mailbox === null || ! $mailbox->canSendOutbound()) {
            return array_merge($zero, ['error' => 'no_mailbox']);
        }

        $suppliers = Supplier::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $supplierIds))))
            ->get();

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $names = [];

        foreach ($suppliers as $supplier) {
            if (trim((string) $supplier->email) === '') {
                $failed++;

                continue;
            }
            $lang = in_array($supplier->language, ['ru', 'en'], true) ? $supplier->language : 'ru';

            // Дедуп: не спрашиваем повторно позиции, по которым этому поставщику
            // уже есть открытый pending-запрос.
            $alreadyPending = $this->pendingCatalogIdsForSupplier((string) $supplier->email);
            $askItems = $items->reject(fn (CatalogItem $ci) => isset($alreadyPending[$ci->id]))->values();
            if ($askItems->isEmpty()) {
                $skipped++;

                continue;
            }

            try {
                $names = $lang === 'en' ? ($edits['names_en'] ?? []) : ($edits['names_ru'] ?? []);
                $oem = $edits['oem'] ?? [];
                $qty = $lang === 'en' ? ($edits['qty_en'] ?? []) : ($edits['qty'] ?? []);
                $greetingTpl = $lang === 'en' ? ($edits['greeting_en'] ?? null) : ($edits['greeting_ru'] ?? null);

                $rows = $askItems->map(fn (CatalogItem $ci) => [
                    'name' => $this->itemName($ci, $names, $lang),
                    'oem' => $this->itemOem($ci, $oem),
                    'brand' => $ci->brand ?: null,
                    'qty' => $this->itemQty($ci, $qty),
                ])->all();

                $personalGreeting = $this->base->personalGreeting($greetingTpl, $supplier, $lang);
                $intro = trim((string) ($lang === 'en' ? ($edits['intro_en'] ?? '') : ($edits['intro_ru'] ?? '')));
                $closing = trim((string) ($lang === 'en' ? ($edits['closing_en'] ?? '') : ($edits['closing_ru'] ?? '')));
                $subject = $lang === 'en' ? 'Price request' : 'Запрос расценки';

                $html = view('emails.supplier-rfq-catalog', [
                    'rows' => $rows,
                    'note' => trim((string) $note),
                    'greeting' => $personalGreeting,
                    'intro' => $intro,
                    'closing' => $closing,
                    'lang' => $lang,
                ])->render();
                $plain = $this->plainBody($personalGreeting, $rows, (string) $note, $lang, $intro, $closing);

                $draft = $this->createDraft($mailbox, $by, $supplier, $subject, $html, $plain);

                $result = $this->sender->sendDraft($draft->id);
                if (! ($result['success'] ?? false)) {
                    $failed++;
                    Log::warning('ProcurementDispatch: send failed', ['supplier_id' => $supplier->id, 'error' => $result['error'] ?? 'unknown']);

                    continue;
                }

                $sentMsg = $result['draft'];
                $inquiry = SupplierInquiry::create([
                    'supplier_email' => mb_strtolower(trim((string) $supplier->email)),
                    'supplier_name' => $supplier->name ?: null,
                    'subject' => $subject,
                    'thread_root_id' => $sentMsg->message_id ?: null,
                    'related_request_id' => null,
                    'status' => 'open',
                    'created_by_user_id' => $by->id,
                ]);
                foreach ($askItems as $ci) {
                    SupplierInquiryItem::create([
                        'supplier_inquiry_id' => $inquiry->id,
                        'catalog_item_id' => $ci->id,
                        'item_name' => $ci->name,
                        'status' => 'pending',
                    ]);
                }
                $sentMsg->forceFill(['supplier_inquiry_id' => $inquiry->id, 'related_request_id' => null])->save();

                $sent++;
                $names[] = (string) ($supplier->name ?: $supplier->email);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('ProcurementDispatch: exception', ['supplier_id' => $supplier->id, 'error' => $e->getMessage()]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'suppliers' => $names, 'error' => null];
    }

    /** Название каталожной позиции: правка > name_en (для en) > name. */
    private function itemName(CatalogItem $ci, array $overrides, string $lang): string
    {
        $override = isset($overrides[$ci->id]) ? trim((string) $overrides[$ci->id]) : '';
        if ($override !== '') {
            return $override;
        }
        if ($lang === 'en') {
            return (string) ($ci->name_en ?: $ci->name ?: $ci->sku);
        }

        return (string) ($ci->name ?: $ci->sku);
    }

    /** Артикул/OEM: правка > brand_article каталога. */
    private function itemOem(CatalogItem $ci, array $overrides): ?string
    {
        if (isset($overrides[$ci->id])) {
            $v = trim((string) $overrides[$ci->id]);

            return $v !== '' ? $v : null;
        }

        return $ci->brand_article ?: null;
    }

    /** Количество: правка менеджера; у каталожной позиции своего кол-ва нет. */
    private function itemQty(CatalogItem $ci, array $overrides): ?string
    {
        if (isset($overrides[$ci->id])) {
            $v = trim((string) $overrides[$ci->id]);

            return $v !== '' ? $v : null;
        }

        return null;
    }

    /** @return array<int, true> catalog_item_id => true (pending по открытым инквайри поставщика) */
    private function pendingCatalogIdsForSupplier(string $email): array
    {
        $ids = SupplierInquiryItem::query()
            ->where('supplier_inquiry_items.status', 'pending')
            ->whereNotNull('supplier_inquiry_items.catalog_item_id')
            ->whereHas('inquiry', fn ($q) => $q->where('status', 'open')
                ->whereRaw('LOWER(supplier_email) = ?', [mb_strtolower(trim($email))]))
            ->pluck('catalog_item_id')->all();

        return array_fill_keys($ids, true);
    }

    private function resolveMailbox(User $by): ?Mailbox
    {
        $personal = $by->primaryOutboundMailbox();
        if ($personal !== null) {
            return $personal;
        }
        $sharedEmail = (string) config('services.mail_outbound.shared_email', 'mail@myzip.ru');

        return Mailbox::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($sharedEmail)])
            ->where('is_active', true)
            ->first();
    }

    private function createDraft(Mailbox $mailbox, User $by, Supplier $supplier, string $subject, string $html, string $plain): EmailMessage
    {
        return EmailMessage::create([
            'mailbox_id' => $mailbox->id,
            'folder' => 'Sent',
            'direction' => MailDirection::Outbound,
            'message_id' => 'draft.'.Str::uuid()->toString().'@mzcorp.ru',
            'in_reply_to' => null,
            'references_header' => null,
            'subject' => mb_substr($subject, 0, 998),
            'from_email' => $mailbox->email,
            'from_name' => $by->name,
            'to_recipients' => [['email' => $supplier->email, 'name' => $supplier->name ?: '']],
            'cc_recipients' => null,
            'sent_at' => null,
            'body_plain' => $plain,
            'body_html' => $html,
            'headers' => ['X-MyLift-Author-User-Id' => (string) $by->id],
            'related_request_id' => null,
            'is_draft' => true,
            'draft_author_user_id' => $by->id,
            'last_edited_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{name:string, oem:?string, brand:?string, qty:?string}>  $rows
     */
    private function plainBody(string $greeting, array $rows, string $note, string $lang, string $intro = '', string $closing = ''): string
    {
        $en = $lang === 'en';
        $intro = $intro !== '' ? $intro : ($en ? 'Please quote price, availability and lead time for the following items:' : 'Просим дать цену, наличие и срок поставки на позиции:');
        $closing = $closing !== '' ? $closing : ($en ? 'Please reply to this email keeping the subject line unchanged.' : 'Отвечайте, пожалуйста, на это письмо, сохраняя тему переписки.');
        $lines = [$greeting !== '' ? $greeting : ($en ? 'Hello,' : 'Здравствуйте!'), '', $intro, ''];
        $n = 1;
        foreach ($rows as $r) {
            $lines[] = ($n++).'. '.implode(' · ', array_filter([$r['name'], $r['oem'] ?? null, $r['brand'] ?? null, $r['qty'] ?? null]));
        }
        if (trim($note) !== '') {
            $lines[] = '';
            $lines[] = $note;
        }
        $lines[] = '';
        $lines[] = $closing;

        return implode("\n", $lines);
    }
}
