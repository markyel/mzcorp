<?php

namespace App\Livewire\Requests\Mail;

use App\Enums\MailDirection;
use App\Enums\Role as RoleEnum;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\LetterTemplate;
use App\Models\Request as RequestModel;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\LetterTemplateService;
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
        if (! $this->isAuthorizedToSend($req, $user)) {
            abort(403, 'Отвечать может только назначенный менеджер, acting (делегат) или admin/РОП/директорат.');
        }
    }

    private function canReply(): bool
    {
        $req = $this->request();
        $user = auth()->user();
        return $user !== null && $this->isAuthorizedToSend($req, $user);
    }

    /**
     * Кто может отправлять письма от имени заявки:
     *  - assigned-менеджер,
     *  - acting (active delegation — на время отсутствия assigned),
     *  - admin / head_of_sales / director (override для пинг-понга проблемных
     *    кейсов; см. 2026-05-28 — admin отправляет от лица менеджера).
     *
     * Mailbox для отправки определяется OutgoingMailboxResolver по
     * Request.assigned_user_id — то есть письмо всегда уходит от ящика
     * закреплённого менеджера, независимо от того кто нажал «Отправить».
     */
    private function isAuthorizedToSend(\App\Models\Request $req, \App\Models\User $user): bool
    {
        if ($req->assigned_user_id === $user->id) {
            return true; // owner
        }
        if (method_exists($req, 'isDelegatedTo') && $req->isDelegatedTo($user)) {
            return true; // acting
        }
        return $user->hasAnyRole([
            \App\Enums\Role::Admin->value,
            \App\Enums\Role::HeadOfSales->value,
            \App\Enums\Role::Director->value,
        ]);
    }

    #[On('open-reply')]
    public function openReply(int $messageId, EmailDraftService $drafts, ?int $requestId = null): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        $this->openFromMessage($messageId, replyAll: false, drafts: $drafts);
    }

    #[On('open-reply-all')]
    public function openReplyAll(int $messageId, EmailDraftService $drafts, ?int $requestId = null): void
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
    public function openCompose(EmailDraftService $drafts, ?int $requestId = null): void
    {
        // Сигнатура: DI ПЕРЕД scalar-args с default value. В PHP 8.4
        // обратный порядок hard-deprecated, в 8.3 warning. Livewire 3
        // матчит named-args из dispatch payload по имени, не по позиции,
        // так что порядок параметров безопасно менять.
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        if (! $this->canReply()) {
            session()->flash('error', 'Отвечать может только назначенный менеджер, acting (делегат) или admin/РОП/директорат.');
            return;
        }
        $req = $this->request();
        try {
            $draft = $drafts->createCompose($req, auth()->user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ComposeForm::openCompose failed', [
                'request_id' => $req->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);
            session()->flash('error', 'Не удалось создать черновик: ' . $e->getMessage());
            return;
        }
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

    /**
     * Вставить шаблон письма в тело (append в конец через пустую строку).
     * Диспатчится из TemplatePicker. Тело — plain-text (как и $bodyText);
     * подпись/цитата приклеиваются при отправке, поэтому шаблон их не несёт.
     * Тема подставляется только если она сейчас пуста — не затираем ввод.
     */
    #[On('insert-template')]
    public function insertTemplate(string $body, EmailDraftService $drafts, ?string $subject = null, ?int $requestId = null): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        $body = trim($body);
        if ($body === '') {
            return;
        }
        $this->bodyText = trim($this->bodyText) === ''
            ? $body
            : rtrim($this->bodyText) . "\n\n" . $body;

        if ($subject !== null && trim($subject) !== '' && trim($this->subject) === '') {
            $this->subject = mb_substr($subject, 0, 998);
        }

        $this->autoSave($drafts); // сразу сохранить в черновик
    }

    /**
     * Сохранить текущее письмо как шаблон (общая библиотека).
     * $parentId — папка назначения (null = корень).
     */
    public function saveAsTemplate(string $name, ?int $parentId, LetterTemplateService $templates): void
    {
        $name = trim($name);
        if ($name === '') {
            $this->addError('bodyText', 'Укажите название шаблона.');
            return;
        }
        if (trim($this->bodyText) === '') {
            $this->addError('bodyText', 'Нельзя сохранить пустой шаблон.');
            return;
        }
        $templates->saveFromLetter(
            name: $name,
            body: $this->bodyText,
            parentId: $parentId,
            subject: trim($this->subject) !== '' ? $this->subject : null,
            by: auth()->user(),
        );
        session()->flash('status', 'Шаблон сохранён.');
    }

    /**
     * Вставить шаблон по id — из inline-меню «Шаблоны» (без модалки-пикера).
     * Тонкая обёртка над insertTemplate(): грузит тело/тему шаблона и делает append.
     */
    public function insertTemplateById(int $id, EmailDraftService $drafts): void
    {
        $tpl = LetterTemplate::templates()->find($id);
        if (! $tpl) {
            return;
        }
        $this->insertTemplate(
            body: (string) $tpl->body,
            drafts: $drafts,
            subject: (string) ($tpl->subject ?? ''),
            requestId: $this->requestId,
        );
    }

    /**
     * Папки для выбора при «Сохранить как шаблон».
     *
     * @return \Illuminate\Support\Collection<int, LetterTemplate>
     */
    #[Computed]
    public function templateFolders()
    {
        return LetterTemplate::folders()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Дерево шаблонов для inline-меню вставки.
     *
     * @return \Illuminate\Support\Collection<int, LetterTemplate>
     */
    #[Computed]
    public function templateTree()
    {
        return app(LetterTemplateService::class)->tree();
    }

    public function close(): void
    {
        $this->open = false;
        // Черновик остаётся — покажем его бейджем в треде сразу, без
        // перезагрузки страницы (Detail::$thread — mount-снапшот).
        if ($this->draftId) {
            $this->dispatch('composer-draft-closed', draftId: $this->draftId, requestId: $this->requestId);
        }
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
        // Сообщаем dropzone (Alpine) очистить <input>.files. Без этого
        // следующий drag&drop собрал бы старые DOM-файлы + новые, и
        // backend сохранил бы дубли.
        $this->dispatch('attachments-uploaded');
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

        // Foundation §6.2: post-send hook для clarification batches.
        // Если в draft.detected_artifacts есть marker `clarification_batch`,
        // помечаем batch как sent + переводим Request в
        // awaiting_client_clarification.
        $this->applyPostSendHooks($result['draft'] ?? $draft);

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
            // Убрать бейдж черновика из треда без перезагрузки.
            $this->dispatch('composer-draft-discarded', draftId: $draft->id, requestId: $this->requestId);
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
     * HTML-превью цитируемого исходного письма (под textarea) — ровно та
     * цитата, что уйдёт в письмо: собирается тем же MailQuoteBuilder, что и
     * при send (attribution + blockquote из body_html). Рендерится в
     * sandbox-iframe с фиксированной высотой — стили письма не текут в CRM.
     *
     * Раньше превью строилось из body_plain (plain-часть письма клиента
     * бывает склеена без переносов — «Просьба выставить счет.Карточка
     * клиента…») и выглядело не так, как то же письмо во вкладке
     * «Переписка» (там рендерится body_html). Баг-репорт 2026-07-09.
     */
    #[Computed]
    public function quotePreviewHtml(): ?string
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

        $quote = app(\App\Services\Mail\MailQuoteBuilder::class)->build($replyTo);

        // Мини-документ для iframe: базовый шрифт как в письме.
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:8px 10px;font-family:-apple-system,Arial,sans-serif;'
            . 'font-size:13px;line-height:1.5;color:#374151;word-break:break-word">'
            . $quote['html']
            . '</body></html>';
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

    /**
     * Foundation §6.2: пост-обработка отправленного письма.
     * Если в detected_artifacts есть marker `clarification_batch`:
     *   - помечаем ClarificationBatch::sent
     *   - переводим Request в awaiting_client_clarification
     *   - audit в request_state_changes (event=clarification_sent)
     */
    private function applyPostSendHooks(\App\Models\EmailMessage $sent): void
    {
        $artifacts = is_array($sent->detected_artifacts ?? null) ? $sent->detected_artifacts : [];

        // Обрабатываем все markers (порядок: clarification → quotation).
        foreach ($artifacts as $marker) {
            if (! is_array($marker)) {
                continue;
            }
            match ($marker['type'] ?? null) {
                'clarification_batch' => $this->handleClarificationBatchHook($sent, $marker),
                'quotation_sent'      => $this->handleQuotationSentHook($sent, $marker),
                default               => null, // unknown marker — ignore
            };
        }
    }

    /**
     * Foundation §6.2 — отправлены уточняющие вопросы клиенту.
     */
    private function handleClarificationBatchHook(\App\Models\EmailMessage $sent, array $marker): void
    {
        $batchId = (int) ($marker['batch_id'] ?? 0);
        if ($batchId === 0) {
            return;
        }
        $batch = \App\Models\ClarificationBatch::find($batchId);
        if (! $batch) {
            return;
        }

        // 1. Mark batch sent.
        $batch->update([
            'status' => \App\Models\ClarificationBatch::STATUS_SENT,
            'sent_at' => now(),
            'sent_message_id' => $sent->id,
        ]);

        // 2. Transition Request → AwaitingClientClarification.
        $targetStatus = $marker['transition_to_status'] ?? null;
        if ($targetStatus !== 'awaiting_client_clarification') {
            return;
        }

        $request = $batch->request;
        if (! $request) {
            return;
        }
        try {
            app(\App\Services\Request\RequestStateService::class)->transitionTo(
                $request,
                \App\Enums\RequestStatus::AwaitingClientClarification,
                auth()->user(),
                [
                    'event' => 'clarification_sent',
                    'comment' => sprintf(
                        'Отправлены уточняющие вопросы клиенту (batch #%d, %d вопросов).',
                        $batch->id,
                        $batch->questions()->count(),
                    ),
                    'payload' => [
                        'clarification_batch_id' => $batch->id,
                        'sent_message_id' => $sent->id,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'ComposeForm: clarification post-send transition failed (non-fatal)',
                [
                    'batch_id' => $batch->id,
                    'request_id' => $request->id,
                    'current_status' => $request->status->value,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Phase 4 — отправлено КП клиенту.
     *  1. QuotationService::markSent — status=sent + sent_at + sent_email_message_id.
     *  2. RequestStateService::transitionTo($req, Quoted) с audit event.
     * Любые исключения — non-fatal (письмо уже отправлено), warning в лог.
     */
    private function handleQuotationSentHook(\App\Models\EmailMessage $sent, array $marker): void
    {
        $qId = (int) ($marker['quotation_id'] ?? 0);
        if ($qId === 0) {
            return;
        }
        $quotation = \App\Models\Quotation::find($qId);
        if (! $quotation) {
            return;
        }

        // 1. Mark quotation sent.
        try {
            app(\App\Services\Quotations\QuotationService::class)->markSent($quotation, $sent->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'ComposeForm: quotation markSent failed (non-fatal)',
                [
                    'quotation_id' => $quotation->id,
                    'sent_message_id' => $sent->id,
                    'error' => $e->getMessage(),
                ],
            );
            // Продолжаем — transition важнее, markSent потом можно поправить руками.
        }

        // 2. Transition Request → Quoted.
        $request = $quotation->request;
        if (! $request) {
            return;
        }
        try {
            app(\App\Services\Request\RequestStateService::class)->transitionTo(
                $request,
                \App\Enums\RequestStatus::Quoted,
                auth()->user(),
                [
                    'event' => 'quotation_sent',
                    'comment' => sprintf(
                        'КП %s v%d отправлено клиенту.',
                        $quotation->internal_code,
                        $quotation->version,
                    ),
                    'payload' => [
                        'quotation_id' => $quotation->id,
                        'quotation_code' => $quotation->internal_code,
                        'quotation_version' => $quotation->version,
                        'sent_message_id' => $sent->id,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            // Не валим — заявка может быть уже в Quoted/AwaitingInvoice/etc.
            \Illuminate\Support\Facades\Log::warning(
                'ComposeForm: quotation post-send transition failed (non-fatal)',
                [
                    'quotation_id' => $quotation->id,
                    'request_id' => $request->id,
                    'current_status' => $request->status->value,
                    'error' => $e->getMessage(),
                ],
            );
        }
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
