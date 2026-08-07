<?php

namespace App\Services\Supplier;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\SupplierInquiry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Фабрика исходящего черновика в тред поставщика для позиция-центричных RFQ
 * (без клиентской заявки — EmailDraftService::createCompose неприменим).
 * Ящик выбираем по цепочке: откуда ушёл исходный RFQ → личный ящик автора →
 * общий (config services.mail_outbound.shared_email). Используется и
 * напоминаниями (SupplierReminderService), и ручным ответом (SupplierReplyService)
 * — единый источник правды для выбора ящика/создания черновика.
 */
class SupplierThreadDraftFactory
{
    /**
     * Пустой draft-EmailMessage в правильном ящике, адресованный поставщику.
     * Тему/тело/тред-заголовки проставляет вызывающий через EmailDraftService.
     */
    public function standaloneDraft(SupplierInquiry $inquiry, ?EmailMessage $orig, User $author): ?EmailMessage
    {
        $mailbox = null;
        if ($orig !== null && $orig->mailbox_id !== null) {
            $candidate = Mailbox::find($orig->mailbox_id);
            if ($candidate !== null && $candidate->is_active && $candidate->canSendOutbound()) {
                $mailbox = $candidate;
            }
        }
        $mailbox ??= $author->primaryOutboundMailbox();
        if ($mailbox === null) {
            $sharedEmail = (string) config('services.mail_outbound.shared_email', 'mail@myzip.ru');
            $mailbox = Mailbox::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($sharedEmail)])
                ->where('is_active', true)
                ->first();
        }
        if ($mailbox === null || ! $mailbox->canSendOutbound()) {
            return null;
        }

        return EmailMessage::create([
            'mailbox_id' => $mailbox->id,
            'folder' => 'Sent',
            'direction' => MailDirection::Outbound,
            'message_id' => 'draft.'.Str::uuid()->toString().'@mzcorp.ru',
            'subject' => '',
            'from_email' => $mailbox->email,
            'from_name' => $author->name,
            'to_recipients' => [['email' => $inquiry->supplier_email, 'name' => $inquiry->supplier_name ?: '']],
            'body_plain' => '',
            'body_html' => '',
            'headers' => ['X-MyLift-Author-User-Id' => (string) $author->id],
            'related_request_id' => null,
            'is_draft' => true,
            'draft_author_user_id' => $author->id,
            'last_edited_at' => now(),
        ]);
    }
}
