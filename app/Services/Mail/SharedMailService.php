<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Enums\Role;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\SharedMailAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
    ) {}

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
                $q->where('from_email', 'not ilike', '%@'.$d);
            }
        }

        return $q;
    }

    /**
     * Цепочка переписки вокруг письма (тред-вид): сам оригинал + входящие
     * продолжения клиента + наши отправленные ответы. Связь по RFC 5322
     * threading-заголовкам (message_id / in_reply_to / references), а не по
     * заявке — у shared-mail писем related_request_id IS NULL.
     *
     * Черновики (is_draft) исключены: наши ответы уже отправлены (sendReply
     * шлёт сразу), а чужие незаконченные draft'ы в тред утекать не должны.
     *
     * @return Collection<int, EmailMessage>
     */
    public function threadFor(EmailMessage $original): Collection
    {
        $ids = collect([$original->message_id])
            ->merge((array) ($original->references_header ?? []))
            ->push($original->in_reply_to)
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->unique()
            ->values();

        $thread = EmailMessage::query()
            ->where('is_draft', false)
            // Технические cross-mailbox копии доставки — не отдельные письма.
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->where(function (Builder $w) use ($ids, $original) {
                $w->whereIn('message_id', $ids->all())
                    ->orWhereIn('in_reply_to', $ids->all())
                    ->orWhere('in_reply_to', $original->message_id);
                foreach ($ids as $mid) {
                    $w->orWhereJsonContains('references_header', $mid);
                }
            })
            ->with(['mailbox:id,email,owner_user_id', 'attachments'])
            ->orderByRaw('sent_at ASC NULLS LAST')
            ->orderBy('id')
            ->limit(50)
            ->get();

        // Одно физическое письмо может лежать в Inbox и в Sent (uniq —
        // mailbox_id+folder+message_id) → в треде задвоилось бы. Оставляем по
        // одной копии на Message-ID (порядок уже по дате).
        $thread = $thread->unique('message_id')->values();

        // Подстраховка: оригинал обязан присутствовать (его message_id уже в
        // $ids, но если он почему-то отфильтровался — добавим вручную).
        if (! $thread->contains('id', $original->id)
            && ! $thread->contains('message_id', $original->message_id)) {
            $thread->push($original->loadMissing(['mailbox:id,email,owner_user_id', 'attachments']));
        }

        return $thread;
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
     * personal-ящика менеджера и отправляет через OutgoingMailSender. Цитата
     * оригинала и подпись приклеиваются в OutgoingMailMimeBuilder при send.
     *
     * @param  array<int, TemporaryUploadedFile>  $uploadedFiles  вложения, приложенные к ответу
     * @param  array<int, array{email: string, name?: string}>  $ccRecipients  копия (CC), необязательно
     * @return array{success: bool, error?: string}
     */
    public function sendReply(
        EmailMessage $original,
        User $manager,
        string $bodyPlain,
        array $uploadedFiles = [],
        array $ccRecipients = [],
    ): array {
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
            'message_id' => 'draft.'.Str::uuid()->toString().'@mzcorp.ru',
            'in_reply_to' => $original->message_id ?: null,
            'references_header' => $references ?: null,
            'subject' => $this->normalizeReplySubject($original->subject),
            'from_email' => $mailbox->email,
            'from_name' => $manager->name,
            'to_recipients' => [['email' => $to, 'name' => (string) ($original->from_name ?? '')]],
            'cc_recipients' => $ccRecipients ?: null,
            'sent_at' => null,
            'body_plain' => $bodyPlain,
            'body_html' => null,
            'headers' => ['X-MyLift-Author-User-Id' => (string) $manager->id],
            'related_request_id' => null,
            'is_draft' => true,
            'draft_author_user_id' => $manager->id,
            'last_edited_at' => now(),
        ]);

        try {
            $this->materializeAttachments($draft, $uploadedFiles);
        } catch (\Throwable $e) {
            Log::warning('SharedMail: attachment materialize failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
        }

        $result = $this->sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            // Отправка не прошла — не оставляем висящий draft с файлами на диске.
            $this->discardFailedDraft($draft);

            return ['success' => false, 'error' => $result['error'] ?? 'Не удалось отправить.'];
        }

        // Ответили — помечаем письмо прочитанным.
        $this->markRead($original, $manager);

        return ['success' => true];
    }

    /**
     * Перенести загруженные файлы в постоянное хранилище и создать
     * EmailAttachment-записи на draft'е (паттерн ComposeForm::uploadAttachments).
     *
     * @param  array<int, mixed>  $uploadedFiles
     */
    private function materializeAttachments(EmailMessage $draft, array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $tmp) {
            if (! $tmp instanceof TemporaryUploadedFile) {
                continue;
            }
            $name = $tmp->getClientOriginalName();
            $mime = $tmp->getMimeType() ?: 'application/octet-stream';
            $size = $tmp->getSize() ?: 0;
            $relativePath = sprintf(
                'mail/%d/drafts/%d/%s',
                $draft->mailbox_id ?? 0,
                $draft->id,
                Str::random(8).'_'.$this->safeFilename($name),
            );
            Storage::disk('local')->put($relativePath, $tmp->get());

            EmailAttachment::create([
                'email_message_id' => $draft->id,
                'filename' => mb_substr($name, 0, 255),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_id' => null,
                'file_path' => $relativePath,
                'disk' => 'local',
                'is_inline' => false,
            ]);
        }
    }

    /** Удалить не отправившийся draft вместе с файлами вложений (best-effort). */
    private function discardFailedDraft(EmailMessage $draft): void
    {
        try {
            foreach ($draft->attachments()->get() as $attachment) {
                try {
                    Storage::disk($attachment->disk)->delete($attachment->file_path);
                } catch (\Throwable) {
                    // orphan-файл переживём — БД источник истины
                }
                $attachment->delete();
            }
            if ($draft->is_draft) {
                $draft->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('SharedMail: discardFailedDraft failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? 'file';

        return mb_substr($name, 0, 80);
    }

    /** «Re: …» без дублирования префикса. */
    private function normalizeReplySubject(?string $subject): string
    {
        $s = trim((string) $subject);
        if ($s === '') {
            return 'Re:';
        }

        return preg_match('/^re:/i', $s) === 1 ? $s : 'Re: '.$s;
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
