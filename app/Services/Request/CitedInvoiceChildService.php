<?php

namespace App\Services\Request;

use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\OutboundQuote;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\RequestItem;
use App\Models\RequestStateChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Клиент по УСПЕШНО ЗАКРЫТОЙ заявке (closed_won) прислал/процитировал наш КП или
 * счёт и просит выставить новый счёт (напр. «те же позиции, ещё 20 шт»).
 *
 * Реанимировать closed_won НЕЛЬЗЯ (в отличие от closed_lost, где работает
 * MailRouter::applyCitedInvoiceRequest → reanimate). Вместо этого создаём
 * ДОЧЕРНЮЮ заявку (inheritance child родителя) со статусом «ждёт счёт»,
 * назначенную ТОМУ ЖЕ менеджеру, что и родитель. Родитель не трогается.
 *
 * За основу позиций — процитированный КП/счёт из истории (outbound_quotes по
 * document_number → outbound_quote_items). Матч по номеру документа уже сделан
 * CitedOutboundQuoteRouter, так что запись КП у нас есть (даже если клиент
 * приложил файл — это наш же документ).
 */
class CitedInvoiceChildService
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly RequestInheritanceService $inheritance,
        private readonly AttentionService $attention,
        private readonly RequestActivityService $activity,
    ) {
    }

    /**
     * Создать дочернюю заявку на счёт по процитированному КП/счёту.
     */
    public function createFromCitedQuote(EmailMessage $message, Request $parent, string $docNo): ?Request
    {
        // Процитированный документ в истории (позиции — отсюда).
        $quote = OutboundQuote::query()
            ->where('document_number', $docNo)
            ->where('request_id', $parent->id)
            ->orderByDesc('id')
            ->first()
            ?? OutboundQuote::query()
                ->where('document_number', $docNo)
                ->orderByDesc('id')
                ->first();

        try {
            $child = DB::transaction(function () use ($message, $parent, $docNo, $quote) {
                $child = Request::create([
                    'internal_code' => $this->codeGenerator->next(),
                    'email_message_id' => $message->id,
                    'status' => RequestStatus::AwaitingInvoice,
                    'subject' => $this->childSubject($parent, $docNo),
                    'client_email' => $parent->client_email,
                    'client_name' => $parent->client_name,
                    'client_company' => $parent->client_company,
                    'organization_id' => $parent->organization_id,
                    'assigned_user_id' => $parent->assigned_user_id,
                    'assigned_at' => $parent->assigned_user_id ? now() : null,
                ]);

                $message->forceFill(['related_request_id' => $child->id])->save();

                // Позиции — из процитированного КП/счёта.
                if ($quote !== null) {
                    $this->copyQuoteItems($quote, $child, $message->id);
                }

                // Тот же менеджер, что у родителя — с аудитом назначения.
                if ($parent->assigned_user_id !== null) {
                    RequestAssignment::create([
                        'request_id' => $child->id,
                        'user_id' => $parent->assigned_user_id,
                        'by_user_id' => null,
                        'reason' => 'inherited_from_won_parent:' . $parent->internal_code,
                        'assigned_at' => now(),
                    ]);
                }

                // Inheritance-связь (родитель ↔ дочерняя), item-маппинги не строим
                // (позиции пришли из КП, а не матчились к родителю).
                $this->inheritance->linkChild($parent, $child, itemMappings: [], linkedBy: 'auto_cited_invoice_won');

                RequestStateChange::create([
                    'request_id' => $child->id,
                    'from_status' => null,
                    'to_status' => RequestStatus::AwaitingInvoice->value,
                    'by_user_id' => null,
                    'event' => 'created_invoice_child_from_won',
                    'comment' => sprintf(
                        'Дочерняя заявка на счёт по %s: родитель %s закрыт успехом (воскрешать нельзя), клиент просит новый счёт.',
                        $docNo,
                        $parent->internal_code,
                    ),
                    'payload' => [
                        'parent_request_id' => $parent->id,
                        'parent_internal_code' => $parent->internal_code,
                        'document_number' => $docNo,
                        'source_email_message_id' => $message->id,
                        'quote_id' => $quote?->id,
                    ],
                ]);

                $this->activity->touch($child, RequestActivityType::RequestCreated);

                return $child;
            });
        } catch (\Throwable $e) {
            Log::error('CitedInvoiceChildService: failed to create child', [
                'parent_request_id' => $parent->id,
                'document_number' => $docNo,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Attention: новая назначенная заявка (info-уровень, всплывёт в пуле).
        try {
            $this->attention->onAssigned($child->fresh());
        } catch (\Throwable $e) {
            Log::warning('CitedInvoiceChildService: attention onAssigned failed (non-fatal)', [
                'child_request_id' => $child->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('CitedInvoiceChildService: invoice child created from won parent', [
            'parent_request_id' => $parent->id,
            'child_request_id' => $child->id,
            'child_internal_code' => $child->internal_code,
            'document_number' => $docNo,
            'assigned_user_id' => $child->assigned_user_id,
        ]);

        return $child->fresh();
    }

    /**
     * Скопировать позиции процитированного КП/счёта в дочернюю заявку.
     */
    private function copyQuoteItems(OutboundQuote $quote, Request $child, int $messageId): void
    {
        $position = 0;
        foreach ($quote->items()->orderBy('position')->get() as $qi) {
            $position++;
            RequestItem::create([
                'request_id' => $child->id,
                'position' => $position,
                'parsed_name' => (string) $qi->raw_name,
                'parsed_article' => $qi->raw_article,
                'parsed_brand' => $qi->raw_brand,
                'parsed_qty' => $qi->quantity ?? 1,
                'parsed_unit' => $qi->unit_measure ?: 'шт.',
                'catalog_item_id' => $qi->matched_catalog_item_id,
                'category' => null,
                'data_source' => 'cited_quote',
                'status' => 'parsed',
                'is_active' => true,
                'source_email_message_id' => $messageId,
            ]);
        }
    }

    private function childSubject(Request $parent, string $docNo): string
    {
        $base = trim((string) $parent->subject);
        $prefix = sprintf('Новый счёт по %s', $docNo);

        return $base !== '' ? mb_substr($prefix . ' — ' . $base, 0, 250) : $prefix;
    }
}
