<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Models\EmailMessage;
use App\Services\Mail\CrossMailboxCopyMatcher;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Cross-mailbox дедуп — ДО любых LLM-шагов. Когда мы APPEND'или оригинал
 * письма в личный ящик менеджера (DeliverToManagerInboxJob), sync личного
 * ящика создаёт ВТОРОЙ row в email_messages с тем же Message-ID. Без этого
 * guard'а MailRouter гнал бы его повторно через gpt-4o categorize →
 * gpt-4o-mini linker AI → parser: лишние $0.01+/копия + риск создать дубль
 * Request.
 *
 * Логика: если есть РАНЕЕ сохранённый EmailMessage с тем же message_id и
 * related_request_id != null — это копия известного письма. Наследуем
 * category + related_request_id, выходим.
 */
final class CrossMailboxCopyHandler implements InboundRoutingHandler
{
    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        if (! $message->message_id) {
            return null;
        }

        $sameIdLinked = EmailMessage::query()
            ->where('message_id', $message->message_id)
            ->where('id', '!=', $message->id)
            ->whereNotNull('related_request_id')
            ->orderBy('id')
            ->get()
            // Совпадение Message-ID ≠ та же копия: Outlook переиспользует
            // Thread-Index как Message-ID, поэтому исходник и пересланный
            // follow-up («FW: …») имеют один id при разных subject/Date.
            // Сверяем subject + sent_at (кейс M-2026-5907).
            ->first(fn (EmailMessage $candidate): bool => CrossMailboxCopyMatcher::isSamePhysicalMessage($message, $candidate));
        if (! $sameIdLinked) {
            return null;
        }

        $artifacts = (array) ($message->detected_artifacts ?? []);
        $artifacts['cross_mailbox_copy_of'] = $sameIdLinked->id;
        $message->forceFill([
            'related_request_id' => $sameIdLinked->related_request_id,
            'category' => $sameIdLinked->category,
            'category_confidence' => $sameIdLinked->category_confidence,
            'category_intent' => $sameIdLinked->category_intent,
            'category_reasoning' => 'Cross-mailbox copy of msg#' . $sameIdLinked->id,
            'categorized_at' => $sameIdLinked->categorized_at ?: now(),
            'detected_artifacts' => $artifacts,
        ])->save();

        Log::info('MailRouter: cross-mailbox copy — skip pipeline', [
            'email_message_id' => $message->id,
            'parent_email_message_id' => $sameIdLinked->id,
            'related_request_id' => $sameIdLinked->related_request_id,
            'message_id' => $message->message_id,
        ]);

        return new RoutingDecision('cross_mailbox_copy', $sameIdLinked->related_request_id, ['copy_of' => $sameIdLinked->id]);
    }
}
