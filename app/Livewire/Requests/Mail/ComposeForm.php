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

    /**
     * Только то, что менеджер ввёл в textarea. Подпись и цитата
     * оригинала приклеиваются при send в OutgoingMailMimeBuilder.
     */
    #[Validate('required|string|min:1')]
    public string $bodyText = '';

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
        // В textarea показываем ТОЛЬКО plain текст, который менеджер ввёл.
        // Подпись и quote оригинала рисуются ниже отдельным preview-блоком.
        $this->bodyText = (string) $draft->body_plain;
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
    public function updatedBodyText(EmailDraftService $drafts): void { $this->autoSave($drafts); }

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
            // В БД храним только plain (что менеджер ввёл). body_html
            // перезапишется при send в Sender → composeFinalBody().
            'body_plain' => $this->bodyText,
            'body_html' => '',
        ]);
    }

    /**
     * Livewire lifecycle: сработает сразу после выбора файлов в input.
     * Автоматически материализует tmp → EmailAttachment, чтобы менеджеру
     * не нужно было отдельной кнопкой «прикрепить» (UX-trap: если
     * нажать «Отправить» с непрожатой кнопкой — файлы НЕ попадают в MIME).
     */
    public function updatedNewFiles(): void
    {
        if (empty($this->newFiles)) {
            return;
        }
        $this->uploadAttachments();
    }

    /**
     * Загрузить новые файлы → перенести в постоянное хранилище → создать
     * EmailAttachment-записи. Вызывается автоматически через updatedNewFiles
     * (и опционально по клику кнопки, если включена в view).
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
        // Сбросим кэш computed-метода чтобы новый список сразу появился.
        unset($this->attachments);
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

        // SMTP-отправка с вложениями к Yandex может занимать 20-40+ сек
        // (TLS handshake + upload). Default PHP-FPM request_terminate_timeout
        // ≈ 30 сек убил бы процесс посреди send'а. Поднимаем для этого
        // конкретного запроса.
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $this->autoSave($drafts); // финальный flush subject/to/cc/body
        // Если менеджер выбрал файлы в input, но не дождался auto-upload —
        // прикручиваем их сейчас, чтобы не уходить без вложений.
        if (! empty($this->newFiles)) {
            $this->uploadAttachments();
        }
        $draft = $this->loadDraftOrFail();

        $result = $sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            $this->addError('subject', $this->describeError((string) ($result['error'] ?? 'unknown')));
            return null;
        }

        session()->flash('status', 'Письмо отправлено.');
        $this->open = false;

        // navigate: false — full reload надёжнее (wire:navigate иногда
        // оставляет btn в loading state если что-то в ответе не так).
        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $this->requestId),
            navigate: false,
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
        $this->reset(['draftId', 'replyToMessageId', 'subject', 'toRaw', 'ccRaw', 'bodyText']);
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

    /**
     * Plain-text превью цитируемого исходного письма (под textarea).
     * Plain, а не HTML, потому что:
     *   - менеджеру важно увидеть «что прицепится», а не идеальный рендер;
     *   - iframe с srcdoc даёт ноль высоты в момент Livewire-render'а
     *     (Alpine fit() не успевает, ResizeObserver не подключён);
     *   - реальная HTML-цитата (с blockquote) собирается в MailQuoteBuilder
     *     при send и идёт в само письмо клиенту.
     */
    #[Computed]
    public function quotePreviewPlain(): ?string
    {
        if (! $this->draftId) {
            return null;
        }
        $draft = EmailMessage::find($this->draftId);
        if (! $draft || ! $draft->in_reply_to) {
            return null;
        }
        $replyTo = EmailMessage::query()
            ->where('message_id', $draft->in_reply_to)
            ->where('is_draft', false)
            ->first();
        if (! $replyTo) {
            return null;
        }
        $from = trim(($replyTo->from_name ? $replyTo->from_name . ' ' : '')
            . '<' . $replyTo->from_email . '>');
        $date = $replyTo->sent_at?->format('d.m.Y H:i') ?? '';
        $header = sprintf('%s, %s писал(а):', $date, $from);

        $body = (string) ($replyTo->body_plain ?: strip_tags(
            (string) $replyTo->body_html
        ));
        // HTML-entity → plain (на случай если body_plain в письме был nl2br'нут).
        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        $body = preg_replace('/\n{3,}/', "\n\n", trim($body));
        // Лимит ~3 КБ — preview, не репродукция письма; раздувать DOM не надо.
        if (mb_strlen($body) > 3000) {
            $body = mb_substr($body, 0, 3000) . "\n\n…(сокращено, полностью пойдёт в письмо)";
        }

        return $header . "\n\n" . $body;
    }

    /**
     * Подпись автора drafft'а — для preview под textarea.
     */
    #[Computed]
    public function signaturePreview(): ?string
    {
        $sig = trim((string) (auth()->user()?->email_signature ?? ''));
        return $sig !== '' ? $sig : null;
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
