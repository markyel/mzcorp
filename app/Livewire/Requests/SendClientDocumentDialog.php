<?php

namespace App\Livewire\Requests;

use App\Enums\DetectorType;
use App\Enums\MailDirection;
use App\Enums\Role;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\OutgoingMailSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Отправка клиенту письма с КП и/или счётом (сделанными в 1С). Открывается с
 * вкладок «КП»/«Счёт» карточки заявки. Ключевое: тип документа ИЗВЕСТЕН по
 * слоту (не гадаем детектором) — КП-файл разбирается как outbound_quotation_full
 * и переводит заявку в «КП отправлено», счёт — как outbound_invoice и в «Счёт
 * выставлен» (через AiDecisionService::recordSuggestion c confidence=1.0 →
 * авто-apply + ParseOutboundQuoteJob по известному типу). Письмо — ответом на
 * последнее письмо клиента (threading), в тему добавляется [M-код] / № 1С.
 * Отправка/вложения/детекция переиспользуют EmailDraftService + OutgoingMailSender.
 */
class SendClientDocumentDialog extends Component
{
    use WithFileUploads;

    public int $requestId;
    public bool $open = false;

    /** Какая вкладка открыла диалог: quote|invoice (для дефолтного шаблона). */
    public string $focus = 'quote';

    public string $subject = '';
    public string $bodyText = '';
    public string $ccRaw = '';

    /** @var array files КП (1С) */
    public $kpFiles = [];
    /** @var array files Счёт (1С) */
    public $invoiceFiles = [];
    /** @var array произвольные файлы */
    public $extraFiles = [];

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    #[On('open-send-client-document')]
    public function openDialog(string $focus = 'quote'): void
    {
        $this->focus = in_array($focus, ['quote', 'invoice'], true) ? $focus : 'quote';
        $this->reset(['kpFiles', 'invoiceFiles', 'extraFiles', 'ccRaw']);
        $req = RequestModel::find($this->requestId);
        $this->subject = $req ? $this->buildSubject($req) : '';
        $this->bodyText = $this->defaultTemplate($this->focus);
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function send(EmailDraftService $drafts, OutgoingMailSender $sender, AiDecisionService $decisions)
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if ($user->hasRole(Role::Secretary->value)) {
            $this->dispatch('toast', message: 'Секретарь только просматривает заявки.', type: 'error');

            return;
        }
        $req = RequestModel::findOrFail($this->requestId);
        if (! $this->canManage($req, $user)) {
            $this->dispatch('toast', message: 'Нет прав отправлять по этой заявке.', type: 'error');

            return;
        }

        $this->validate([
            'bodyText' => 'required|string|max:20000',
            'kpFiles.*' => 'file|max:25600',
            'invoiceFiles.*' => 'file|max:25600',
            'extraFiles.*' => 'file|max:25600',
        ], [], ['bodyText' => 'текст письма']);

        if (empty($this->kpFiles) && empty($this->invoiceFiles) && empty($this->extraFiles)) {
            $this->addError('kpFiles', 'Прикрепите КП и/или счёт (хотя бы один файл).');

            return;
        }
        if (trim((string) $req->client_email) === '') {
            $this->dispatch('toast', message: 'У заявки нет e-mail клиента.', type: 'error');

            return;
        }

        // Ответ на последнее письмо клиентской переписки (threading); если писем
        // нет — новое письмо на client_email.
        $last = $this->lastClientMessage($req);
        $draft = $last !== null
            ? $drafts->createReply($req, $last, $user, false)
            : $drafts->createCompose($req, $user);

        $drafts->update($draft, [
            'subject' => $this->subject !== '' ? $this->subject : $this->buildSubject($req),
            'to_recipients' => [['email' => $req->client_email, 'name' => (string) ($req->client_name ?? '')]],
            'cc_recipients' => $this->parseCc(),
            'body_plain' => $this->bodyText,
            'body_html' => '',
        ]);

        // Материализуем файлы, ЗАПОМИНАЯ id вложений по типу — тип известен точно.
        $kpAttIds = $this->materialize($this->kpFiles, $draft);
        $invAttIds = $this->materialize($this->invoiceFiles, $draft);
        $this->materialize($this->extraFiles, $draft);

        $result = $sender->sendDraft($draft->id);
        if (! ($result['success'] ?? false)) {
            try {
                $drafts->delete($draft->fresh() ?? $draft);
            } catch (\Throwable $e) {
                // ignore
            }
            $this->dispatch('toast', message: 'Не удалось отправить: ' . ($result['error'] ?? 'ошибка'), type: 'error');

            return;
        }
        /** @var EmailMessage $sent */
        $sent = $result['draft'];

        // Детерминированная обработка: тип из слота, БЕЗ угадывания детектором.
        // recordSuggestion(confidence=1.0) авто-применит статус (КП→Quoted,
        // счёт→Invoiced), ParseOutboundQuoteJob распарсит вложение по типу.
        // Идемпотентно с последующей sync-детекцией (по email+type / attachment).
        // ФОРС-применение (не полагаемся на detector.auto_mode): apply()
        // идемпотентен (isFinal → return), поэтому перевод статуса гарантирован.
        // При обоих документах КП применяем ПЕРВЫМ, счёт ВТОРЫМ — итог Invoiced
        // (приоритет счёта). ParseOutboundQuoteJob распарсит вложение по типу.
        if ($kpAttIds !== []) {
            $d = $decisions->recordSuggestion(DetectorType::OutboundQuotationFull, $req->fresh(), $sent, 1.0, ['source' => 'client_document_dialog']);
            $decisions->apply($d, $user, ['source' => 'client_document_dialog']);
            foreach ($kpAttIds as $aid) {
                $this->dispatchParse($aid, DetectorType::OutboundQuotationFull);
            }
        }
        if ($invAttIds !== []) {
            $d = $decisions->recordSuggestion(DetectorType::OutboundInvoice, $req->fresh(), $sent, 1.0, ['source' => 'client_document_dialog']);
            $decisions->apply($d, $user, ['source' => 'client_document_dialog']);
            foreach ($invAttIds as $aid) {
                $this->dispatchParse($aid, DetectorType::OutboundInvoice);
            }
        }

        $this->open = false;
        $this->reset(['kpFiles', 'invoiceFiles', 'extraFiles', 'ccRaw']);
        $this->dispatch('toast', message: 'Письмо клиенту отправлено. Документы обрабатываются.', type: 'success');
        $this->dispatch('request-state-changed');
    }

    private function dispatchParse(int $attachmentId, DetectorType $type): void
    {
        try {
            ParseOutboundQuoteJob::dispatch($attachmentId, $type->value, false);
        } catch (\Throwable $e) {
            Log::warning('SendClientDocument: dispatch parse failed (non-fatal)', [
                'attachment_id' => $attachmentId,
                'type' => $type->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Материализовать загруженные tmp-файлы в EmailAttachment на черновике.
     *
     * @return array<int> id созданных вложений
     */
    private function materialize($files, EmailMessage $draft): array
    {
        $ids = [];
        foreach ((array) $files as $tmp) {
            if ($tmp === null) {
                continue;
            }
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
            $att = EmailAttachment::create([
                'email_message_id' => $draft->id,
                'filename' => mb_substr((string) $original, 0, 255),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'content_id' => null,
                'file_path' => $relativePath,
                'disk' => 'local',
                'is_inline' => false,
            ]);
            $ids[] = $att->id;
        }

        return $ids;
    }

    /**
     * Якорь для threading — последнее письмо КЛИЕНТА (не любого письма треда!).
     *
     * Заявка может нести переписку нескольких сторон под одним related_request_id
     * с supplier_inquiry_id=NULL (клиент + поставщики + внутренние). Раньше брали
     * просто последнее письмо треда → якорь съезжал на суб-тред поставщика, и КП
     * уходил клиенту с чужой темой, вне его цепочки (кейс M-2026-11902: клиент
     * order@liftway.store, а якорем стала наша переписка с unisystem.si «Request
     * M-2026-11901»). Теперь строго по клиенту:
     *   1) последнее ВХОДЯЩЕЕ от самого клиента (его message_id → In-Reply-To →
     *      письмо ляжет в тред клиента);
     *   2) иначе (веб-форма/пересылка через релей — клиент не писал напрямую) —
     *      последнее письмо, где клиент СТОРОНА (from/to/cc);
     *   3) фолбэк — последнее письмо треда.
     */
    private function lastClientMessage(RequestModel $req): ?EmailMessage
    {
        $clientEmail = mb_strtolower(trim((string) $req->client_email));

        $base = fn () => EmailMessage::query()
            ->where('related_request_id', $req->id)
            ->whereNull('supplier_inquiry_id')
            ->where('is_draft', false);

        if ($clientEmail !== '') {
            $fromClient = $base()
                ->where('direction', MailDirection::Inbound->value)
                ->whereRaw('lower(from_email) = ?', [$clientEmail])
                ->orderByDesc('sent_at')->orderByDesc('id')->first();
            if ($fromClient !== null) {
                return $fromClient;
            }

            $withClient = $base()
                ->where(function ($q) use ($clientEmail) {
                    $q->whereRaw('lower(from_email) = ?', [$clientEmail])
                        ->orWhereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(to_recipients, '[]'::jsonb)) e WHERE lower(e->>'email') = ?)", [$clientEmail])
                        ->orWhereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(cc_recipients, '[]'::jsonb)) e WHERE lower(e->>'email') = ?)", [$clientEmail]);
                })
                ->orderByDesc('sent_at')->orderByDesc('id')->first();
            if ($withClient !== null) {
                return $withClient;
            }
        }

        return $base()->orderByDesc('sent_at')->orderByDesc('id')->first();
    }

    private function buildSubject(RequestModel $req): string
    {
        $last = $this->lastClientMessage($req);
        $base = trim((string) ($last?->subject ?: $req->subject ?: 'Заявка'));
        if (! preg_match('/^\s*re:/i', $base)) {
            $base = 'Re: ' . $base;
        }
        $code = trim((string) $req->internal_code);
        if ($code !== '' && mb_stripos($base, $code) === false) {
            $suffix = ' [' . $code . ']';
            if (filled($req->onec_number)) {
                $suffix .= ' / ' . trim((string) $req->onec_number);
            }
            $base .= $suffix;
        }

        return mb_substr($base, 0, 255);
    }

    private function defaultTemplate(string $focus): string
    {
        if ($focus === 'invoice') {
            return "Здравствуйте!\n\nВо вложении направляем счёт на оплату по вашей заявке.\n"
                . "Будем рады ответить на вопросы.\n\nС уважением,";
        }

        return "Здравствуйте!\n\nВо вложении направляем коммерческое предложение по вашей заявке.\n"
            . "Готовы обсудить детали и ответить на вопросы.\n\nС уважением,";
    }

    /** @return array<int, array{email:string, name:string}> */
    private function parseCc(): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $this->ccRaw) ?: [] as $chunk) {
            $email = mb_strtolower(trim((string) $chunk));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['email' => $email, 'name' => ''];
            }
        }

        return $out;
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name), 0, 120) ?: 'file';
    }

    private function canManage(RequestModel $req, \App\Models\User $user): bool
    {
        if ($user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value])) {
            return true;
        }

        return method_exists($req, 'isAccessibleBy')
            ? $req->isAccessibleBy($user)
            : (int) $req->assigned_user_id === (int) $user->id;
    }

    public function render()
    {
        return view('livewire.requests.send-client-document-dialog');
    }
}
