<?php

namespace App\Livewire\Requests\Mail;

use App\Enums\MailDirection;
use App\Enums\Role as RoleEnum;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Compose / Reply / Reply-all форма в карточке заявки (Phase 1.9 UI-переписки).
 *
 * Inline-форма в табе «Переписка» — раскрывается из дочерних сообщений
 * (через event open-reply), либо из шапки таба (open-compose), либо из
 * draft-badge (open-draft).
 *
 * Authorization: отправлять может только assigned manager (по плану §6).
 * РОП/директор/секретарь могут просматривать тред, но не отправлять.
 */
class ComposeForm extends Component
{
    use WithFileUploads;

    public int $requestId;
    public ?int $draftId = null;
    public ?int $replyToMessageId = null;
    public string $mode = 'reply'; // reply | reply_all | compose
    public bool $open = false;

    #[Validate('required|string|max:998')]
    public string $subject = '';

    #[Validate('required|string|max:4000')]
    public string $toRaw = '';

    #[Validate('nullable|string|max:4000')]
    public string $ccRaw = '';

    #[Validate('required|string|min:1')]
    public string $bodyHtml = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newFiles = [];

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    private function request(): RequestModel
    {
        return RequestModel::with('assignedUser')->findOrFail($this->requestId);
    }

    private function ensureAuthorized(): void
    {
        $req = $this->request();
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if ($req->assigned_user_id !== $user->id) {
            abort(403, 'Отвечать может только назначенный менеджер.');
        }
    }

    private function canReply(): bool
    {
        $req = $this->request();
        $user = auth()->user();
        return $user !== null && $req->assigned_user_id === $user->id;
    }

    #[On('open-reply')]
    public function openReply(int $messageId, ?int $requestId = null, EmailDraftService $drafts): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        $this->openFromMessage($messageId, replyAll: false, drafts: $drafts);
    }

    #[On('open-reply-all')]
    public function openReplyAll(int $messageId, ?int $requestId = null, EmailDraftService $drafts): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        $this->openFromMessage($messageId, replyAll: true, drafts: $drafts);
    }

    private function openFromMessage(int $messageId, bool $replyAll, EmailDraftService $drafts): void
    {
        if (! $this->canReply()) {
            session()->flash('error', 'Отвечать может только назначенный менеджер.');
            return;
        }
        $req = $this->request();
        $replyTo = EmailMessage::where('related_request_id', $req->id)
            ->whereKey($messageId)
            ->first();
        if (! $replyTo) {
            $this->addError('subject', 'Письмо для ответа не найдено.');
            return;
        }

        $draft = $drafts->createReply($req, $replyTo, auth()->user(), $replyAll);
        $this->hydrateFromDraft($draft);
        $this->replyToMessageId = $replyTo->id;
        $this->mode = $replyAll ? 'reply_all' : 'reply';
        $this->open = true;
    }

    #[On('open-compose')]
    public function openCompose(?int $requestId = null, EmailDraftService $drafts): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        if (! $this->canReply()) {
            session()->flash('error', 'Отвечать может только назначенный менеджер.');
            return;
        }
        $req = $this->request();
        $draft = $drafts->createCompose($req, auth()->user());
        $this->hydrateFromDraft($draft);
        $this->replyToMessageId = null;
        $this->mode = 'compose';
        $this->open = true;
    }

    #[On('open-draft')]
    public function openDraft(int $draftId, ?int $requestId = null): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        if (! $this->canReply()) {
            return;
        }
        $draft = EmailMessage::with('attachments')
            ->where('related_request_id', $this->requestId)
            ->where('is_draft', true)
            ->where('draft_author_user_id', auth()->id())
            ->whereKey($draftId)
            ->first();
        if (! $draft) {
            return;
        }
        $this->hydrateFromDraft($draft);
        $this->replyToMessageId = null;
        $this->mode = $draft->in_reply_to ? 'reply' : 'compose';
        $this->open = true;
    }

    private function hydrateFromDraft(EmailMessage $draft): void
    {
        $this->draftId = $draft->id;
        $this->subject = (string) $draft->subject;
        $this->toRaw = $this->formatRecipients((array) ($draft->to_recipients ?? []));
        $this->ccRaw = $this->formatRecipients((array) ($draft->cc_recipients ?? []));
        $this->bodyHtml = (string) $draft->body_html;
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Auto-save при изменении полей (вызывается через wire:model.live.debounce.1500ms).
     */
    public function updatedSubject(EmailDraftService $drafts): void { $this->autoSave($drafts); }
    public function updatedToRaw(EmailDraftService $drafts): void { $this->autoSave($drafts); }
    public function updatedCcRaw(EmailDraftService $drafts): void { $this->autoSave($drafts); }
    public function updatedBodyHtml(EmailDraftService $drafts): void { $this->autoSave($drafts); }

    private function autoSave(EmailDraftService $drafts): void
    {
        if (! $this->draftId) {
            return;
        }
        $draft = EmailMessage::where('is_draft', true)
            ->where('draft_author_user_id', auth()->id())
            ->whereKey($this->draftId)
            ->first();
        if (! $draft) {
            return;
        }
        $drafts->update($draft, [
            'subject' => mb_substr($this->subject, 0, 998),
            'to_recipients' => $this->parseRecipients($this->toRaw),
            'cc_recipients' => $this->parseRecipients($this->ccRaw),
            'body_html' => $this->bodyHtml,
            'body_plain' => $this->htmlToPlain($this->bodyHtml),
        ]);
    }

    /**
     * Загрузить новые файлы → перенести в постоянное хранилище → создать
     * EmailAttachment-записи. Вызывается из view по клику «Добавить».
     */
    public function uploadAttachments(): void
    {
        $this->ensureAuthorized();
        $this->validate([
            'newFiles.*' => 'file|max:25600', // 25 МБ на файл
        ]);
        $draft = $this->loadDraftOrFail();

        foreach ($this->newFiles as $tmp) {
            $original = $tmp->getClientOriginalName();
            $mime = $tmp->getMimeType() ?: 'application/octet-stream';
            $size = $tmp->getSize() ?: 0;
            $relativePath = sprintf(
                'mail/%d/drafts/%d/%s',
                $draft->mailbox_id ?? 0,
                $draft->id,
                Str::random(8) . '_' . $this->safeFilename($original),
            );
            Storage::disk('local')->put($relativePath, $tmp->get());

            EmailAttachment::create([
                'email_message_id' => $draft->id,
                'filename' => mb_substr($original, 0, 255),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_id' => null,
                'file_path' => $relativePath,
                'disk' => 'local',
                'is_inline' => false,
            ]);
        }
        $this->newFiles = [];
    }

    public function removeAttachment(int $attachmentId, EmailDraftService $drafts): void
    {
        $this->ensureAuthorized();
        $draft = $this->loadDraftOrFail();
        $attachment = EmailAttachment::where('email_message_id', $draft->id)
            ->whereKey($attachmentId)
            ->first();
        if (! $attachment) {
            return;
        }
        $drafts->removeAttachment($draft, $attachment);
    }

    public function send(EmailDraftService $drafts, OutgoingMailSender $sender)
    {
        $this->ensureAuthorized();
        $this->validate();
        $this->autoSave($drafts); // финальный flush
        $draft = $this->loadDraftOrFail();

        $result = $sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            $this->addError('subject', $this->describeError((string) ($result['error'] ?? 'unknown')));
            return null;
        }

        session()->flash('status', 'Письмо отправлено.');
        $this->open = false;

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $this->requestId),
            navigate: true,
        );
    }

    public function discard(EmailDraftService $drafts): void
    {
        if (! $this->draftId) {
            $this->open = false;
            return;
        }
        $draft = EmailMessage::where('is_draft', true)
            ->where('draft_author_user_id', auth()->id())
            ->whereKey($this->draftId)
            ->first();
        if ($draft) {
            $drafts->delete($draft);
        }
        $this->reset(['draftId', 'replyToMessageId', 'subject', 'toRaw', 'ccRaw', 'bodyHtml']);
        $this->open = false;
    }

    #[Computed]
    public function mailboxLabel(): ?string
    {
        if (! $this->draftId) {
            return null;
        }
        $draft = EmailMessage::with('mailbox')->find($this->draftId);
        $mailbox = $draft?->mailbox;
        if (! $mailbox) {
            return 'не назначен — обратитесь к РОПу';
        }
        $assignedId = $this->request()->assigned_user_id;
        $isPersonal = $mailbox->owner_user_id === $assignedId;
        $suffix = $isPersonal ? '' : ' (общий)';

        return $mailbox->email . $suffix;
    }

    #[Computed]
    public function attachments()
    {
        if (! $this->draftId) {
            return collect();
        }
        return EmailAttachment::where('email_message_id', $this->draftId)->get();
    }

    public function render()
    {
        return view('livewire.requests.mail.compose-form');
    }

    /* ----------------------- helpers ----------------------- */

    private function loadDraftOrFail(): EmailMessage
    {
        if (! $this->draftId) {
            abort(404);
        }
        $draft = EmailMessage::where('is_draft', true)
            ->where('draft_author_user_id', auth()->id())
            ->where('related_request_id', $this->requestId)
            ->whereKey($this->draftId)
            ->first();
        if (! $draft) {
            abort(404);
        }
        return $draft;
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    private function parseRecipients(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[,;\n]+/u', $raw) ?: [];
        $out = [];
        foreach ($items as $item) {
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

    /**
     * @param  array<int, array{email: string, name?: string}>  $list
     */
    private function formatRecipients(array $list): string
    {
        $out = [];
        foreach ($list as $r) {
            $email = (string) ($r['email'] ?? '');
            $name = trim((string) ($r['name'] ?? ''));
            if ($email === '') {
                continue;
            }
            $out[] = $name !== '' ? "{$name} <{$email}>" : $email;
        }
        return implode(', ', $out);
    }

    private function htmlToPlain(string $html): string
    {
        $s = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $s = preg_replace('/<\/p\s*>/i', "\n\n", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        return trim($s);
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', $name) ?? 'file';
        return mb_substr($name, 0, 80);
    }

    private function describeError(string $code): string
    {
        return match ($code) {
            'no_mailbox' => 'Не назначен ящик для отправки — обратитесь к РОПу.',
            'mailbox_cannot_send' => 'Ящик неактивен или OAuth-токен не обновился.',
            'oauth_refresh_failed' => 'Не удалось обновить OAuth-токен — РОП должен переподключить ящик.',
            'smtp_send_failed' => 'Не удалось отправить через SMTP — попробуйте ещё раз.',
            'not_a_draft' => 'Черновик уже отправлен.',
            default => 'Не удалось отправить письмо: ' . $code,
        };
    }
}
