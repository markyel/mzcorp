<?php

namespace App\Services\Bot;

use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\MessengerMessage;
use App\Models\OutboundQuote;
use App\Models\Request;
use App\Models\RequestStateChange;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Клиенту заявки из бота — о ходе заявки в тот же мессенджер (этап 2).
 *
 * Повод — запись в журнале статусов (RequestStateChange): через неё идут и
 * кнопки менеджера, и авто-КП, и распознанные КП/счета. КП и счёт уходят
 * клиенту и письмом; в мессенджер — коротко плюс PDF из того самого письма
 * (payload.sent_message_id), иначе из последнего исходящего письма заявки.
 */
class BotRequestNotifier
{
    /** Документы из письма — не больше и не тяжелее (лимит Telegram 50 МБ, держим скромнее). */
    private const MAX_FILES = 3;

    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly BotMessengers $messengers)
    {
    }

    /** Куда ушёл документ/вопрос: письмом, в этот чат, и туда и туда, никуда (статус поставили вручную). */
    public const VIA_EMAIL = 'email';

    public const VIA_CHAT = 'chat';

    public const VIA_BOTH = 'both';

    public const VIA_NONE = 'none';

    /**
     * Что сказать клиенту о переходе; null — переход клиенту неинтересен. «Отправили на почту» — только если
     * письмо правда ушло: раньше говорили всегда, а КП и счёт менеджер прислал файлом в чат — клиент ждал
     * письма (MZ2026-01628, 17.11.2026).
     */
    public static function text(Request $request, RequestStatus $to, string $via = self::VIA_EMAIL): ?string
    {
        $code = '<b>'.e((string) $request->internal_code).'</b>';
        $email = e((string) $request->client_email);
        $where = match ($via) {
            self::VIA_EMAIL => ' — отправили его на '.$email,
            self::VIA_CHAT => ' — прислали его здесь, в чате',
            self::VIA_BOTH => ' — отправили его на '.$email.' и прислали здесь, в чате',
            default => '',
        };
        $question = match ($via) {
            self::VIA_EMAIL => ' — вопрос отправили на '.$email.'. Ответить можно письмом или здесь.',
            self::VIA_BOTH => ' — вопрос отправили на '.$email.' и прислали здесь. Ответить можно письмом или здесь.',
            default => '. Ответить можно здесь.',
        };

        return match ($to) {
            RequestStatus::Quoted => '📄 По заявке '.$code.' готово коммерческое предложение'.$where.'.',
            RequestStatus::PartiallyQuoted => '📄 По заявке '.$code.' готово предложение по части позиций'.$where.'. По остальным ищем.',
            RequestStatus::AwaitingClientClarification => '❓ По заявке '.$code.' менеджеру нужно уточнение'.$question,
            RequestStatus::Invoiced => '🧾 По заявке '.$code.' выставлен счёт'.$where.'.',
            RequestStatus::Paid => '✅ Оплата по заявке '.$code.' получена. Спасибо!',
            RequestStatus::ClosedWon => '✅ Заявка '.$code.' выполнена. Спасибо, что выбрали MyZip!',
            default => null,
        };
    }

    public function notify(RequestStateChange $change): void
    {
        $request = Request::query()->with('messengerChat')->find($change->request_id);
        $chat = $request?->messengerChat;
        $to = RequestStatus::tryFrom((string) $change->to_status);
        if ($request === null || $chat === null || $to === null || $chat->blocked_at !== null) {
            return;
        }
        $messenger = $this->messengers->for($chat->channel);
        if (self::text($request, $to) === null || $messenger === null) {
            return;
        }

        // Куда документ ушёл на самом деле: письмо клиенту за сутки и/или файл менеджера в этом чате.
        $isDocument = in_array($to, [RequestStatus::Quoted, RequestStatus::PartiallyQuoted, RequestStatus::Invoiced], true);
        $emailDocs = $isDocument ? $this->documents($request, $change) : [];
        $emailed = $isDocument ? $emailDocs !== [] : $this->emailedRecently($request);
        $inChat = $this->sentInChat($request, $isDocument ? $to === RequestStatus::Invoiced : null);
        $via = $emailed && $inChat ? self::VIA_BOTH : ($emailed ? self::VIA_EMAIL : ($inChat ? self::VIA_CHAT : self::VIA_NONE));
        $text = (string) self::text($request, $to, $via);

        $buttons = [[['text' => '💬 Написать менеджеру', 'data' => 'reply:'.$request->id]]];
        $ok = $messenger->sendText((string) $chat->chat_id, $text, $buttons);
        $sentFiles = [];
        // PDF из письма — в чат, если менеджер его туда уже не прислал.
        if ($isDocument && ! $inChat) {
            foreach ($emailDocs as $att) {
                $bytes = $this->bytes($att);
                if ($bytes !== null && $messenger->sendDocument((string) $chat->chat_id, $bytes, (string) $att->filename)) {
                    $sentFiles[] = (string) $att->filename;
                }
            }
        }

        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'direction' => 'out',
            'kind' => 'notify',
            'text' => $text,
            'meta' => array_filter(['status' => $to->value, 'files' => $sentFiles, 'send_failed' => $ok ? null : true]),
        ]);
        // Ответ клиента без выбора заявки уйдёт по этой, последней.
        $chat->stateSet('active_request_id', $request->id);
        $chat->save();
    }

    /** @return list<EmailAttachment> PDF из письма, которым ушёл документ */
    private function documents(Request $request, RequestStateChange $change): array
    {
        $messageId = (int) (($change->payload ?? [])['sent_message_id'] ?? ($change->payload ?? [])['email_message_id'] ?? 0);
        $message = $messageId > 0 ? EmailMessage::find($messageId) : null;
        if ($message === null) {
            // Распознанный документ без ссылки на письмо — последнее исходящее с PDF за сутки.
            $message = EmailMessage::query()
                ->where('related_request_id', $request->id)
                ->whereNull('supplier_inquiry_id')
                ->where('direction', 'outbound')
                ->where('is_draft', false)
                ->where('sent_at', '>=', now()->subDay())
                ->whereHas('attachments', fn ($q) => $q->where('mime_type', 'application/pdf'))
                ->latest('sent_at')
                ->first();
        }
        if ($message === null) {
            return [];
        }

        return $message->attachments()
            ->where('mime_type', 'application/pdf')
            ->where('size_bytes', '<=', self::MAX_FILE_BYTES)
            ->orderBy('id')
            ->limit(self::MAX_FILES)
            ->get()->all();
    }

    /** Клиенту за сутки ушло письмо по заявке (вопрос-уточнение письмом). */
    private function emailedRecently(Request $request): bool
    {
        return EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->whereNull('supplier_inquiry_id')
            ->where('direction', 'outbound')
            ->where('is_draft', false)
            ->where('sent_at', '>=', now()->subDay())
            ->exists();
    }

    /**
     * Менеджер прислал это в чат: для документа — PDF под КП/счёт по имени за сутки или любой PDF за час
     * (имя бывает любым); для вопроса ($invoice = null) — любое его сообщение за сутки.
     */
    private function sentInChat(Request $request, ?bool $invoice): bool
    {
        $sent = MessengerMessage::query()
            ->where('request_id', $request->id)->where('direction', 'out')->where('kind', 'manager')
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')->limit(30)->get(['id', 'meta', 'created_at']);
        foreach ($sent as $m) {
            if (! empty(($m->meta ?? [])['send_failed'])) {
                continue;
            }
            if ($invoice === null) {
                return true;
            }
            foreach ((array) (($m->meta ?? [])['attachments'] ?? []) as $att) {
                if (self::isDocumentPdf((string) ($att['name'] ?? ''), (string) ($att['mime'] ?? ''), $invoice, $m->created_at)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** PDF подходит как КП/счёт: по имени — за сутки, без говорящего имени — только свежий (час). */
    public static function isDocumentPdf(string $name, string $mime, bool $invoice, ?\Illuminate\Support\Carbon $at): bool
    {
        $pdf = $mime === 'application/pdf' || str_ends_with(mb_strtolower($name), '.pdf');
        if (! $pdf) {
            return false;
        }

        return preg_match($invoice ? self::INVOICE_FILE_RE : self::QUOTE_FILE_RE, $name) === 1
            || ($at !== null && $at->greaterThanOrEqualTo(now()->subHour()));
    }

    /** PDF в переписке бота, который менеджер прислал как КП или как счёт — по имени файла. */
    private const QUOTE_FILE_RE = '/предложени|(?<![a-zа-яё])кп(?![a-zа-яё])|quot|offer/iu';

    private const INVOICE_FILE_RE = '/сч[её]т|invoice/iu';

    /**
     * Последний КП или счёт заявки, ушедший клиенту: распознанный документ из письма (OutboundQuote)
     * или PDF, который менеджер прислал в переписке бота (по заявкам из бота КП шлют файлом в чат —
     * MZ2026-00881, MZ2026-00946). Новее из двух; null — не было. «Ещё раз» в «Моих заявках» бота.
     *
     * @return array{name: string, disk: string, path: string, at: \Illuminate\Support\Carbon}|null
     */
    public static function latestDocument(Request $request, bool $invoice): ?array
    {
        $types = $invoice
            ? [DetectorType::OutboundInvoice->value]
            : [DetectorType::OutboundQuotationFull->value, DetectorType::OutboundQuotationPartial->value];

        $found = [];
        $oq = OutboundQuote::query()
            ->with('attachment')
            ->where('request_id', $request->id)
            ->whereIn('document_type', $types)
            ->whereIn('status', [OutboundQuote::STATUS_PARSED, OutboundQuote::STATUS_MATCHED])
            ->whereNotNull('email_attachment_id')
            ->whereHas('attachment', fn ($q) => $q->where('size_bytes', '<=', self::MAX_FILE_BYTES))
            ->latest('id')
            ->first();
        if ($oq !== null) {
            $found[] = ['name' => $oq->displayFilename(), 'disk' => $oq->attachment->disk ?: 'local', 'path' => (string) $oq->attachment->file_path, 'at' => $oq->created_at ?? now()->subYears(10)];
        }

        $re = $invoice ? self::INVOICE_FILE_RE : self::QUOTE_FILE_RE;
        $sent = MessengerMessage::query()
            ->where('request_id', $request->id)->where('direction', 'out')->where('kind', 'manager')
            ->latest('id')->limit(30)->get(['id', 'meta', 'created_at']);
        foreach ($sent as $m) {
            if (! empty(($m->meta ?? [])['send_failed'])) {
                continue;
            }
            foreach (array_reverse((array) (($m->meta ?? [])['attachments'] ?? [])) as $att) {
                $name = (string) ($att['name'] ?? '');
                $pdf = ($att['mime'] ?? '') === 'application/pdf' || str_ends_with(mb_strtolower($name), '.pdf');
                if ($pdf && ! empty($att['path']) && (int) ($att['size'] ?? 0) <= self::MAX_FILE_BYTES && preg_match($re, $name)) {
                    $found[] = ['name' => $name, 'disk' => BotIntakeStore::DISK, 'path' => (string) $att['path'], 'at' => $m->created_at];
                    break 2;
                }
            }
        }

        return collect($found)->sortByDesc(fn ($d) => $d['at']->getTimestamp())->first();
    }

    /** Отправить документ в чат; false — файла нет на диске или мессенджер не принял. */
    public function sendDocument(\App\Models\MessengerChat $chat, array $doc, string $caption): bool
    {
        $messenger = $this->messengers->for($chat->channel);
        try {
            $disk = Storage::disk($doc['disk']);
            $bytes = $disk->exists($doc['path']) ? $disk->get($doc['path']) : null;
        } catch (\Throwable $e) {
            Log::warning('BotRequestNotifier: document unreadable', ['path' => $doc['path'], 'error' => $e->getMessage()]);
            $bytes = null;
        }
        if ($messenger === null || $bytes === null) {
            return false;
        }

        return $messenger->sendDocument((string) $chat->chat_id, $bytes, $doc['name'], $caption);
    }

    private function bytes(EmailAttachment $att): ?string
    {
        try {
            $disk = Storage::disk($att->disk ?: 'local');

            return $disk->exists($att->file_path) ? $disk->get($att->file_path) : null;
        } catch (\Throwable $e) {
            Log::warning('BotRequestNotifier: attachment unreadable', ['attachment_id' => $att->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
