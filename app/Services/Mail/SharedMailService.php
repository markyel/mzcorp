<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\SharedMailAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Шаринг почты выбывших менеджеров (раздел «Почта выбывших» / «Почта»).
 *
 * Письма из ЛИЧНЫХ ящиков СЕЙЧАС недоступных менеджеров, которые НЕ привязаны
 * к заявкам (related_request_id IS NULL) — их переписка иначе никому не видна,
 * пока менеджер в отсутствии. РОП/директор назначают ответственного; назначенный
 * менеджер отвечает СО СВОЕГО ящика. Список — живой запрос (без материализации),
 * состояние (назначение + прочитанность) — в shared_mail_assignments.
 *
 * См. [[request-access-delegation]] (делегирование заявок — для заявочных писем
 * у нас уже есть; это про НЕ-заявочную переписку).
 */
class SharedMailService
{
    public function __construct(
        private readonly OutgoingMailSender $sender,
    ) {
    }

    /**
     * Id СЕЙЧАС недоступных request-handler'ов (менеджеры/РОП в отсутствии).
     *
     * @return array<int, int>
     */
    public function unavailableManagerIds(): array
    {
        return User::role(Role::requestHandlerRoles())
            ->get(['id', 'archived_at', 'unavailable_from', 'unavailable_until'])
            ->filter(fn (User $u) => $u->isUnavailable())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Базовый запрос ленты: входящие письма из личных ящиков недоступных
     * менеджеров, НЕ привязанные к заявкам, без cross-mailbox копий и без
     * внутренних (наш домен) отправителей.
     */
    public function baseQuery(): Builder
    {
        $ids = $this->unavailableManagerIds();

        $q = EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->whereNull('related_request_id')
            ->whereHas('mailbox', fn ($m) => $m->whereIn('owner_user_id', $ids ?: [0]))
            // Технические cross-mailbox копии доставки — не отдельные письма.
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL");

        // Внутренние отправители (наш домен) — это не входящая клиентская переписка.
        foreach (array_filter((array) config('services.mail.internal_domains', [])) as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d !== '') {
                $q->where('from_email', 'not ilike', '%@' . $d);
            }
        }

        return $q;
    }

    /** Назначить (или снять) ответственного за письмо. */
    public function assign(EmailMessage $email, ?int $managerId, User $by): void
    {
        SharedMailAssignment::updateOrCreate(
            ['email_message_id' => $email->id],
            [
                'assigned_user_id' => $managerId,
                'assigned_by_user_id' => $by->id,
                'assigned_at' => $managerId ? now() : null,
            ],
        );
    }

    public function markRead(EmailMessage $email, User $by): void
    {
        SharedMailAssignment::updateOrCreate(
            ['email_message_id' => $email->id],
            ['read_at' => now(), 'read_by_user_id' => $by->id],
        );
    }

    public function markUnread(EmailMessage $email): void
    {
        SharedMailAssignment::where('email_message_id', $email->id)
            ->update(['read_at' => null, 'read_by_user_id' => null]);
    }

    /**
     * Ответить на письмо выбывшего СО СВОЕГО ящика (только назначенный менеджер).
     * Строит reply-draft (threading + recipient = отправитель оригинала) из
     * personal-ящика менеджера и отправляет через OutgoingMailSender.
     *
     * @return array{success: bool, error?: string}
     */
    public function sendReply(EmailMessage $original, User $manager, string $bodyPlain): array
    {
        $bodyPlain = trim($bodyPlain);
        if ($bodyPlain === '') {
            return ['success' => false, 'error' => 'Пустой текст ответа.'];
        }

        $mailbox = $manager->primaryOutboundMailbox();
        if ($mailbox === null || ! $mailbox->canSendOutbound()) {
            return ['success' => false, 'error' => 'У вас нет настроенного ящика для отправки.'];
        }

        $to = trim((string) $original->from_email);
        if ($to === '') {
            return ['success' => false, 'error' => 'У письма не определён отправитель.'];
        }

        $references = $this->mergeReferences(
            (array) ($original->references_header ?? []),
            $original->message_id,
        );

        $draft = EmailMessage::create([
            'mailbox_id' => $mailbox->id,
            'folder' => 'Sent',
            'direction' => MailDirection::Outbound->value,
            'message_id' => 'draft.' . Str::uuid()->toString() . '@mzcorp.ru',
            'in_reply_to' => $original->message_id ?: null,
            'references_header' => $references ?: null,
            'subject' => $this->normalizeReplySubject($original->subject),
            'from_email' => $mailbox->email,
            'from_name' => $manager->name,
            'to_recipients' => [['email' => $to, 'name' => (string) ($original->from_name ?? '')]],
            'cc_recipients' => null,
            'sent_at' => null,
            'body_plain' => $bodyPlain,
            'body_html' => null,
            'headers' => ['X-MyLift-Author-User-Id' => (string) $manager->id],
            'related_request_id' => null,
            'is_draft' => true,
            'draft_author_user_id' => $manager->id,
            'last_edited_at' => now(),
        ]);

        $result = $this->sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Не удалось отправить.'];
        }

        // Ответили — помечаем письмо прочитанным.
        $this->markRead($original, $manager);

        return ['success' => true];
    }

    /** «Re: …» без дублирования префикса. */
    private function normalizeReplySubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ($s === '') {
            return 'Re:';
        }

        return preg_match('/^re:/i', $s) === 1 ? $s : 'Re: ' . $s;
    }

    /**
     * Слить References + добавить message_id оригинала (без дублей).
     *
     * @param  array<int, string>  $existing
     * @return array<int, string>
     */
    private function mergeReferences(array $existing, ?string $messageId): array
    {
        $refs = array_values(array_filter(array_map('strval', $existing)));
        if ($messageId && ! in_array($messageId, $refs, true)) {
            $refs[] = $messageId;
        }

        return $refs;
    }
}
