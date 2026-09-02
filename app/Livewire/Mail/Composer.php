<?php

namespace App\Livewire\Mail;

use App\Enums\Role;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request as RequestModel;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\MailboxAccessService;
use App\Services\Mail\OutboundReplyHooks;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Плавающий композер почтового клиента (Фаза 2) — ответ / ответить всем /
 * написать. Гибрид: письмо привязано к заявке → createReply/createCompose +
 * OutboundReplyHooks (статусы КП/уточнений); свободное → createReplyFree/
 * createComposeFree, без вмешательства в заявки.
 *
 * Встраивается один раз в App\Livewire\Mail\Client; открывается событиями
 * mail-open-reply / mail-open-compose / mail-open-draft.
 *
 * Тело — plain text (подпись и цитата приклеиваются в MimeBuilder при send,
 * как в ComposeForm). Богатый HTML-редактор + пересылка — Фаза 2b.
 */
class Composer extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public ?int $draftId = null;

    public ?int $relatedRequestId = null;

    public ?int $replyToMessageId = null;

    public string $mode = 'reply'; // reply | reply_all | compose

    #[Validate('required|string|max:998')]
    public string $subject = '';

    #[Validate('required|string|max:4000')]
    public string $toRaw = '';

    #[Validate('nullable|string|max:4000')]
    public string $ccRaw = '';

    #[Validate('required|string|min:1')]
    public string $bodyText = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $newFiles = [];

    private function user(): User
    {
        $u = auth()->user();
        abort_unless($u?->hasAnyRole([Role::Manager->value, Role::Admin->value]), 403);

        return $u;
    }

    /* ------------------------------ open ------------------------------ */

    #[On('mail-open-reply')]
    public function openReply(int $messageId, EmailDraftService $drafts, bool $all = false): void
    {
        $anchor = $this->findAccessibleMessage($messageId);
        if (! $anchor) {
            $this->dispatch('toast', message: 'Письмо не найдено или недоступно.', type: 'error');

            return;
        }

        // Привязано к заявке → путь заявки (с гибрид-хуками при send).
        if ($anchor->related_request_id) {
            $req = RequestModel::with('assignedUser')->find($anchor->related_request_id);
            if (! $req || ! $this->canSendForRequest($req)) {
                $this->dispatch('toast', message: 'Ответ по заявке доступен назначенному менеджеру, замещающему или руководителю.', type: 'error');

                return;
            }
            // Письмо поставщика — отвечаем клиенту заявки (compose), не поставщику.
            if (app(OutboundReplyHooks::class)->isSupplierMessage($anchor)) {
                $draft = $this->createOrToast(fn () => $drafts->createCompose($req, $this->user()));
                if (! $draft) {
                    return;
                }
                $this->fillFromDraft($draft, 'compose', null);
                $this->dispatch('toast', message: 'Это письмо поставщика — ответ адресован клиенту заявки.', type: 'info');

                return;
            }
            $draft = $this->createOrToast(fn () => $drafts->createReply($req, $anchor, $this->user(), $all));
            if (! $draft) {
                return;
            }
            $this->fillFromDraft($draft, $all ? 'reply_all' : 'reply', $anchor->id, $req->id);

            return;
        }

        // Свободная переписка.
        $draft = $this->createOrToast(fn () => $drafts->createReplyFree($anchor, $this->user(), $all));
        if (! $draft) {
            return;
        }
        $this->fillFromDraft($draft, $all ? 'reply_all' : 'reply', $anchor->id, null);
    }

    #[On('mail-open-reply-all')]
    public function openReplyAll(int $messageId, EmailDraftService $drafts): void
    {
        $this->openReply($messageId, $drafts, all: true);
    }

    #[On('mail-open-compose')]
    public function openCompose(int $mailboxId, EmailDraftService $drafts): void
    {
        if (! app(MailboxAccessService::class)->canAccessMailbox($this->user(), $mailboxId)) {
            $this->dispatch('toast', message: 'Ящик недоступен.', type: 'error');

            return;
        }
        $mailbox = Mailbox::find($mailboxId);
        if (! $mailbox) {
            return;
        }
        $draft = $this->createOrToast(fn () => $drafts->createComposeFree($mailbox, $this->user()));
        if (! $draft) {
            return;
        }
        $this->fillFromDraft($draft, 'compose', null, null);
    }

    /**
     * Создать черновик, отловив сбой (напр. резолвер не нашёл ящик отправки →
     * mailbox_id NOT NULL). Возвращает null + тост при ошибке.
     */
    private function createOrToast(\Closure $fn): ?EmailMessage
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('Mail\Composer: draft create failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Не удалось создать черновик — возможно, не назначен ящик для отправки. Обратитесь к РОПу.', type: 'error');

            return null;
        }
    }

    #[On('mail-open-draft')]
    public function openDraft(int $draftId): void
    {
        $draft = EmailMessage::with('attachments')
            ->where('is_draft', true)
            ->where('draft_author_user_id', $this->user()->id)
            ->whereKey($draftId)
            ->first();
        if (! $draft) {
            return;
        }
        $this->fillFromDraft($draft, $draft->in_reply_to ? 'reply' : 'compose', null, $draft->related_request_id);
    }

    private function fillFromDraft(EmailMessage $draft, string $mode, ?int $replyToId, ?int $requestId = null): void
    {
        $this->draftId = $draft->id;
        $this->relatedRequestId = $requestId ?? $draft->related_request_id;
        $this->replyToMessageId = $replyToId;
        $this->mode = $mode;
        $this->subject = (string) $draft->subject;
        $this->toRaw = $this->formatRecipients((array) ($draft->to_recipients ?? []));
        $this->ccRaw = $this->formatRecipients((array) ($draft->cc_recipients ?? []));
        $this->bodyText = (string) $draft->body_plain;
        $this->newFiles = [];
        $this->resetErrorBag();
        $this->open = true;
    }

    /* ---------------------------- autosave ---------------------------- */

    public function updatedSubject(EmailDraftService $drafts): void
    {
        $this->autoSave($drafts);
    }

    public function updatedToRaw(EmailDraftService $drafts): void
    {
        $this->autoSave($drafts);
    }

    public function updatedCcRaw(EmailDraftService $drafts): void
    {
        $this->autoSave($drafts);
    }

    public function updatedBodyText(EmailDraftService $drafts): void
    {
        $this->autoSave($drafts);
    }

    private function autoSave(EmailDraftService $drafts): void
    {
        $draft = $this->loadDraft();
        if (! $draft) {
            return;
        }
        $drafts->update($draft, [
            'subject' => mb_substr($this->subject, 0, 998),
            'to_recipients' => $this->parseRecipients($this->toRaw),
            'cc_recipients' => $this->parseRecipients($this->ccRaw),
            'body_plain' => $this->bodyText,
            'body_html' => '',
        ]);
    }

    /* --------------------------- attachments -------------------------- */

    public function updatedNewFiles(): void
    {
        if (! empty($this->newFiles)) {
            $this->uploadAttachments();
        }
    }

    public function uploadAttachments(): void
    {
        $draft = $this->loadDraft();
        if (! $draft) {
            return;
        }
        $this->validate(['newFiles.*' => 'file|max:25600']);

        foreach ($this->newFiles as $tmp) {
            $original = $tmp->getClientOriginalName();
            $relativePath = sprintf(
                'mail/%d/drafts/%d/%s',
                $draft->mailbox_id ?? 0,
                $draft->id,
                Str::random(8).'_'.$this->safeFilename($original),
            );
            Storage::disk('local')->put($relativePath, $tmp->get());
            EmailAttachment::create([
                'email_message_id' => $draft->id,
                'filename' => mb_substr($original, 0, 255),
                'mime_type' => $tmp->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $tmp->getSize() ?: 0,
                'content_id' => null,
                'file_path' => $relativePath,
                'disk' => 'local',
                'is_inline' => false,
            ]);
        }
        $this->newFiles = [];
        unset($this->attachments);
        $this->dispatch('mail-attachments-uploaded');
    }

    public function removeAttachment(int $attachmentId, EmailDraftService $drafts): void
    {
        $draft = $this->loadDraft();
        if (! $draft) {
            return;
        }
        $att = EmailAttachment::where('email_message_id', $draft->id)->whereKey($attachmentId)->first();
        if ($att) {
            $drafts->removeAttachment($draft, $att);
            unset($this->attachments);
        }
    }

    #[Computed]
    public function attachments()
    {
        return $this->draftId
            ? EmailAttachment::where('email_message_id', $this->draftId)->get()
            : collect();
    }

    #[Computed]
    public function fromMailboxLabel(): ?string
    {
        if (! $this->draftId) {
            return null;
        }
        $mb = EmailMessage::with('mailbox')->find($this->draftId)?->mailbox;

        return $mb?->email ?? 'ящик не назначен';
    }

    #[Computed]
    public function signaturePreview(): ?string
    {
        $sig = trim((string) (auth()->user()?->email_signature ?? ''));

        return $sig !== '' ? $sig : null;
    }

    /* ------------------------------ send ------------------------------ */

    public function send(EmailDraftService $drafts, OutgoingMailSender $sender)
    {
        $this->validate();

        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $this->autoSave($drafts);
        if (! empty($this->newFiles)) {
            $this->uploadAttachments();
        }
        $draft = $this->loadDraft();
        if (! $draft) {
            $this->addError('subject', 'Черновик не найден.');

            return null;
        }

        // Авторизация отправки: заявка → assigned/acting/privileged; свободное →
        // доступ к ящику.
        if (! $this->canSendDraft($draft)) {
            $this->addError('subject', 'Недостаточно прав для отправки этого письма.');

            return null;
        }

        $result = $sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            $this->addError('subject', $this->describeError((string) ($result['error'] ?? 'unknown')));

            return null;
        }

        $sent = $result['draft'] ?? $draft;

        // Гибрид: хуки только для писем, привязанных к заявке.
        if ($sent->related_request_id) {
            try {
                $hooks = app(OutboundReplyHooks::class);
                if (! $hooks->applyPostSendHooks($sent, $this->user())) {
                    $hooks->detectOutboundDocuments($sent);
                }
            } catch (\Throwable $e) {
                Log::warning('Mail\Composer: post-send hooks failed (non-fatal)', [
                    'email_message_id' => $sent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->reset(['draftId', 'relatedRequestId', 'replyToMessageId', 'subject', 'toRaw', 'ccRaw', 'bodyText', 'newFiles']);
        $this->open = false;
        $this->dispatch('mail-sent');
        $this->dispatch('toast', message: 'Письмо отправлено.', type: 'success');

        return null;
    }

    public function discard(EmailDraftService $drafts): void
    {
        $draft = $this->loadDraft();
        if ($draft) {
            $drafts->delete($draft);
        }
        $this->reset(['draftId', 'relatedRequestId', 'replyToMessageId', 'subject', 'toRaw', 'ccRaw', 'bodyText', 'newFiles']);
        $this->open = false;
        $this->dispatch('mail-sent'); // обновить список (черновик исчез)
    }

    public function close(): void
    {
        $this->open = false;
    }

    /* ----------------------------- helpers ---------------------------- */

    private function loadDraft(): ?EmailMessage
    {
        if (! $this->draftId) {
            return null;
        }

        return EmailMessage::where('is_draft', true)
            ->where('draft_author_user_id', auth()->id())
            ->whereKey($this->draftId)
            ->first();
    }

    /** Письмо в пределах доступных пользователю ящиков (защита доступа). */
    private function findAccessibleMessage(int $id): ?EmailMessage
    {
        $ids = app(MailboxAccessService::class)->mailboxIdsFor($this->user());

        return EmailMessage::whereIn('mailbox_id', $ids)->whereKey($id)->first();
    }

    private function canSendForRequest(RequestModel $req): bool
    {
        $user = $this->user();
        if ($req->assigned_user_id === $user->id) {
            return true;
        }
        if (method_exists($req, 'isDelegatedTo') && $req->isDelegatedTo($user)) {
            return true;
        }

        return $user->hasAnyRole([Role::Admin->value, Role::HeadOfSales->value, Role::Director->value]);
    }

    private function canSendDraft(EmailMessage $draft): bool
    {
        if ($draft->related_request_id) {
            $req = RequestModel::find($draft->related_request_id);

            return $req !== null && $this->canSendForRequest($req);
        }

        // Свободное письмо — нужен доступ к ящику-отправителю.
        return $draft->mailbox_id !== null
            && app(MailboxAccessService::class)->canAccessMailbox($this->user(), (int) $draft->mailbox_id);
    }

    /** @return array<int, array{email: string, name: string}> */
    private function parseRecipients(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[,;\n]+/u', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.*?)<([^>]+)>$/u', $item, $m)) {
                $email = trim($m[2]);
                $name = trim($m[1], " \t\"");
            } else {
                $email = $item;
                $name = '';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['email' => $email, 'name' => $name];
            }
        }

        return $out;
    }

    /** @param array<int, array{email: string, name?: string}> $list */
    private function formatRecipients(array $list): string
    {
        $out = [];
        foreach ($list as $r) {
            $email = (string) ($r['email'] ?? '');
            if ($email === '') {
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            $out[] = $name !== '' ? "{$name} <{$email}>" : $email;
        }

        return implode(', ', $out);
    }

    private function safeFilename(string $name): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? 'file', 0, 80);
    }

    private function describeError(string $code): string
    {
        return match ($code) {
            'no_mailbox' => 'Не назначен ящик для отправки — обратитесь к РОПу.',
            'mailbox_cannot_send' => 'Ящик неактивен или OAuth-токен не обновился.',
            'oauth_refresh_failed' => 'Не удалось обновить OAuth-токен — переподключите ящик.',
            'smtp_send_failed' => 'Не удалось отправить через SMTP — попробуйте ещё раз.',
            'not_a_draft' => 'Черновик уже отправлен.',
            default => 'Не удалось отправить письмо: '.$code,
        };
    }

    public function render()
    {
        return view('livewire.mail.composer');
    }
}
