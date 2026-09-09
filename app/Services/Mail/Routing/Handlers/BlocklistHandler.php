<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\BlocklistKind;
use App\Enums\EmailCategory;
use App\Models\RoutedMail;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Mail\SenderBlocklistService;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Support\Facades\Log;

/**
 * Стоп-лист отправителей: ДО AI-категоризации (экономим токены) и ДО
 * reply-linker. Две ветки по kind записи:
 *  - supplier → это пул поставщика: НЕ создаём заявку, но письмо ПРОЧИТЫВАЕМ —
 *    прикрепляем как переписку поставщика (читаемо в /dashboard/suppliers,
 *    category=supplier_reply). См. BlocklistKind.
 *  - spam → отбрасываем (Irrelevant).
 * hit_count инкрементится внутри match().
 */
final class BlocklistHandler implements InboundRoutingHandler
{
    public function __construct(
        private readonly SenderBlocklistService $blocklist,
        private readonly SupplierInquiryService $supplierInquiries,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $blockEntry = $this->blocklist->match($message->from_email);
        if ($blockEntry === null) {
            return null;
        }

        if ($blockEntry->kind === BlocklistKind::Supplier) {
            try {
                $inquiry = $this->supplierInquiries->ingestSupplierMessage($message);
                Log::info('MailRouter: supplier-blocklist sender — read as supplier correspondence', [
                    'email_message_id' => $message->id,
                    'supplier_inquiry_id' => $inquiry?->id,
                    'from_email' => $message->from_email,
                ]);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: supplier-blocklist ingest failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return new RoutingDecision('blocklist_supplier', null, ['from' => (string) $message->from_email]);
        }

        // spam-kind → отбрасываем. Полный набор полей категории, как у
        // классификатора (иначе confidence/intent оставались с прошлого
        // значения, а повторная категоризация блокировалась по categorized_at).
        $message->forceFill([
            'category' => EmailCategory::Irrelevant->value,
            'category_confidence' => 1.0,
            'category_intent' => null,
            'category_reasoning' => 'Blocked by sender_blocklist (from='.$message->from_email.')',
            'categorized_at' => now(),
        ])->save();

        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->category,
            'action_taken' => 'blocklist_skipped',
            'success' => true,
            'processed_at' => now(),
        ]);

        Log::info('MailRouter: skip — sender in blocklist (spam)', [
            'email_message_id' => $message->id,
            'from_email' => $message->from_email,
            'subject' => mb_substr((string) $message->subject, 0, 80),
        ]);

        return new RoutingDecision('blocklist_spam', null, ['from' => (string) $message->from_email]);
    }
}
