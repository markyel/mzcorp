<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Менеджер открыл переписку заявки — письма клиента в ней прочитаны (решение
 * заказчика 29.10.2026, M-2026-21587): в «Почте» они больше не висят
 * непрочитанными, как если бы он открыл их там.
 *
 * Отмечаются входящие письма треда и их копии в других ящиках (то же
 * Message-ID или cross_mailbox_copy_of): в «Почте» менеджер видит копию в
 * личном ящике, а в карточке — оригинал из info@. Правило прочитанности то же,
 * что в «Почте» (Mail\Client::readStateUserByMailbox): у личного ящика она
 * владельца, у общих — того, кто смотрит. Чужой личный ящик (прежний менеджер,
 * директор в копии) не трогаем — его прочитанность не наша.
 * У своего личного ящика \Seen ставится и на сервере (ImapSeenSyncService) —
 * иначе pull флагов вернул бы письмо в непрочитанные.
 */
class RequestThreadReadService
{
    public function __construct(
        private readonly MailReadService $read,
        private readonly ImapSeenSyncService $seen,
        private readonly MailUnreadCounter $counter,
    ) {}

    /**
     * @param  Collection<int, EmailMessage>  $thread  письма карточки заявки
     * @return int сколько писем стало прочитанными
     */
    public function markClientLettersRead(Collection $thread, User $user): int
    {
        $inbound = $thread->filter(fn (EmailMessage $m) => $m->direction === MailDirection::Inbound && ! $m->is_draft);
        if ($inbound->isEmpty()) {
            return 0;
        }

        $ids = $inbound->pluck('id')->map(fn ($id) => (int) $id)->all();
        $messageIds = $inbound->pluck('message_id')->filter()->unique()->values()->all();

        $candidates = EmailMessage::withHistory()
            ->where('is_draft', false)
            ->where('direction', MailDirection::Inbound->value)
            ->where(function ($q) use ($ids, $messageIds) {
                // Условие в форме частичного индекса email_messages_cross_mailbox_copy_of_idx.
                $q->whereIn('id', $ids)
                    ->orWhereRaw(
                        "((detected_artifacts->>'cross_mailbox_copy_of') ~ '^[0-9]+$' AND ((detected_artifacts->>'cross_mailbox_copy_of')::bigint) IN (".implode(',', $ids).'))'
                    );
                if ($messageIds !== []) {
                    $q->orWhereIn('message_id', $messageIds);
                }
            })
            ->get(['id', 'mailbox_id']);

        $mailboxes = Mailbox::query()->whereIn('id', $candidates->pluck('mailbox_id')->unique())->get(['id', 'type', 'owner_user_id'])->keyBy('id');
        $mine = $candidates->filter(function (EmailMessage $m) use ($mailboxes, $user) {
            $box = $mailboxes->get($m->mailbox_id);

            // Личный ящик с владельцем — только свой; общий — прочитанность смотрящего.
            return $box === null
                || $box->type !== MailboxType::Personal
                || $box->owner_user_id === null
                || (int) $box->owner_user_id === (int) $user->id;
        });

        $alreadyRead = DB::table('email_message_user_states')
            ->where('user_id', $user->id)
            ->whereIn('email_message_id', $mine->pluck('id'))
            ->whereNotNull('read_at')
            ->pluck('email_message_id')
            ->map(fn ($id) => (int) $id)
            ->flip();
        $unread = $mine->reject(fn (EmailMessage $m) => $alreadyRead->has((int) $m->id));
        if ($unread->isEmpty()) {
            return 0;
        }

        $unreadIds = $unread->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->read->markManyRead($unreadIds, $user);

        try {
            $this->seen->pushSeen($unreadIds, $user, true);
        } catch (\Throwable $e) {
            Log::warning('RequestThreadReadService: IMAP \Seen push failed (non-fatal)', [
                'user_id' => $user->id,
                'ids' => $unreadIds,
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($unread->pluck('mailbox_id')->unique() as $mailboxId) {
            $this->counter->forget((int) $mailboxId, (int) $user->id);
        }

        return count($unreadIds);
    }
}
