<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use App\Support\RequestCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Привязка письма к существующей заявке из почтовика («Привязать к заявке»).
 *
 * Менеджер выгружает КП/счёт из 1С и пишет клиенту НОВОЕ письмо, не ответ в треде:
 * такое письмо заявке не достаётся (09.11.2026: за неделю 23 исходящих с КП/счётом
 * без заявки; «000372869» с «Предложение МЗ-372869.pdf» мимо MZ2026-00524). Привязка
 * ставит в заявку письмо, его ещё не привязанную переписку и копии того же письма в
 * других ящиках; исходящее проходит штатное распознавание документа
 * (OutboundDocumentDetectionService: гард «адресовано клиенту», решение детектора,
 * разбор вложений → OutboundQuote / Invoice, статус по распознанному документу).
 * Входящее просто встаёт в заявку — разбор ответа клиента не запускается.
 *
 * Перепривязки нет: письмо, уже стоящее в заявке, не трогаем. Кто и когда привязал —
 * в detected_artifacts (ARTIFACT), как у «Счета → без заявки».
 */
class MailToRequestLinker
{
    public const ARTIFACT = 'manual_link_to_request';

    private const LIMIT = 8;

    public function __construct(
        private readonly OutboundDocumentDetectionService $outbound,
    ) {
    }

    /** Почему письмо привязать нельзя, или null — можно. */
    public function refusal(EmailMessage $message): ?string
    {
        if ($message->related_request_id) {
            return 'Письмо уже в заявке.';
        }
        if ($message->is_draft) {
            return 'Черновик к заявке не привязывается.';
        }
        $category = $message->category instanceof EmailCategory ? $message->category->value : (string) $message->category;
        if ($category === EmailCategory::SupplierReply->value
            || $message->mailbox?->isProcurementMailbox()
            || app(SupplierCcInboxService::class)->isRfqInboxMessage($message)) {
            return 'Это переписка с поставщиком — к клиентской заявке её не привязывают.';
        }

        return null;
    }

    /**
     * Подсказки: заявка с номером из темы или имён вложений, затем заявки того же клиента
     * (открытые первыми). Только доступные человеку.
     *
     * @return list<array{request: Request, why: string}>
     */
    public function candidates(EmailMessage $message, ?User $user): array
    {
        $text = (string) $message->subject.' '.$message->attachments->pluck('filename')->implode(' ');
        ['codes' => $codes, 'onec' => $onec] = self::referencedNumbers($text);

        $out = [];
        $add = function (Collection $rows, string $why) use (&$out, $user): void {
            foreach ($rows as $r) {
                if (! isset($out[$r->id]) && $r->isAccessibleBy($user)) {
                    $out[$r->id] = ['request' => $r, 'why' => $why];
                }
            }
        };

        if ($codes !== []) {
            $add(Request::query()->whereIn(DB::raw('upper(internal_code)'), $codes)->get(), 'номер заявки в письме');
        }
        if ($onec !== []) {
            $add(Request::query()->whereIn('onec_number', $onec)->get(), 'номер 1С в письме');
        }
        $emails = self::counterpartyEmails($message);
        if ($emails !== []) {
            $add(Request::query()
                ->whereIn(DB::raw('lower(client_email)'), $emails)
                ->orderByRaw("status in ('closed_won', 'closed_lost')")
                ->orderByDesc('id')
                ->limit(20)
                ->get(), 'тот же клиент');
        }

        return array_slice(array_values($out), 0, self::LIMIT);
    }

    /**
     * Поиск заявки по номеру, номеру 1С или адресу клиента.
     *
     * @return Collection<int, Request>
     */
    public function search(string $query, ?User $user): Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return collect();
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';

        return Request::query()
            ->where(fn ($w) => $w->where('internal_code', 'ilike', $like)
                ->orWhere('onec_number', 'ilike', $like)
                ->orWhere('client_email', 'ilike', $like))
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->filter(fn (Request $r) => $r->isAccessibleBy($user))
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Привязать письмо и его ещё не привязанную переписку к заявке.
     *
     * @param  Collection<int, EmailMessage>  $thread  переписка письма, как её видит почтовик
     * @return array{linked: int, documents: int, client: ?string}
     *
     * @throws \DomainException  привязать нельзя (причина — текстом для человека)
     */
    public function link(EmailMessage $anchor, Collection $thread, Request $request, User $user): array
    {
        if (($why = $this->refusal($anchor)) !== null) {
            throw new \DomainException($why);
        }
        if (! $request->isAccessibleBy($user)) {
            throw new \DomainException('Нет доступа к заявке '.$request->internal_code.'.');
        }

        $letters = $thread
            ->filter(fn (EmailMessage $m) => ! $m->related_request_id && ! $m->is_draft && $this->refusal($m) === null)
            ->push($anchor)
            ->unique('id');

        // Копии тех же писем (Inbox + Sent, личный ящик + общий) — тоже в заявку, иначе
        // в другом ящике то же письмо останется «без заявки».
        $messageIds = $letters->pluck('message_id')->map(fn ($v) => trim((string) $v))->filter()->unique()->values();
        if ($messageIds->isNotEmpty()) {
            $letters = $letters->merge(EmailMessage::withHistory()->withoutRawSource()
                ->whereNull('related_request_id')
                ->where('is_draft', false)
                ->whereIn('message_id', $messageIds)
                ->get())->unique('id');
        }

        $this->settleHistory($letters);

        $stamp = [
            'type' => self::ARTIFACT,
            'request_id' => $request->id,
            'created_at' => now()->toIso8601String(),
            'created_by_user_id' => $user->id,
        ];
        DB::transaction(function () use ($letters, $request, $stamp): void {
            foreach ($letters as $m) {
                $artifacts = is_array($m->detected_artifacts ?? null) ? $m->detected_artifacts : [];
                $artifacts[] = $stamp;
                $m->forceFill(['related_request_id' => $request->id, 'detected_artifacts' => $artifacts])->save();
            }
        });

        // До распознавания исходящих: их гард сверяет получателя с клиентом заявки.
        $client = $this->adoptClient($request, $letters);

        // Исходящее — штатное распознавание документа, по одному на письмо (копии делят
        // вложения оригинала). Сам сервис проверяет, что письмо адресовано клиенту заявки.
        $documents = 0;
        $outbound = $letters
            ->filter(fn (EmailMessage $m) => $m->direction === MailDirection::Outbound)
            ->sortByDesc(fn (EmailMessage $m) => $m->attachments()->count())
            ->unique(fn (EmailMessage $m) => ($mid = trim((string) $m->message_id)) !== '' ? mb_strtolower($mid) : 'row-'.$m->id);
        foreach ($outbound as $m) {
            if ($m->is_history) {
                continue; // текст с сервера не загрузился (письма там уже нет) — разбирать нечего
            }
            $this->outbound->run($m->fresh(['attachments']), $request);
            $documents++;
        }

        Log::info('MailToRequestLinker: letter linked to request by hand', [
            'email_message_id' => $anchor->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'linked_ids' => $letters->pluck('id')->values()->all(),
            'outbound_detected' => $documents,
            'client_adopted' => $client,
            'by_user_id' => $user->id,
        ]);

        return ['linked' => $letters->count(), 'documents' => $documents, 'client' => $client];
    }

    /**
     * Письма из истории ящика становятся живой перепиской, как у «Это заявка!»: текст и
     * вложения — с сервера, флаг истории снимаем. Иначе карточка заявки их не видит
     * (M-2026-21657, 09.11.2026: письмо клиента привязали, а в заявке его нет).
     * Письма на сервере уже нет — оставляем как есть, привязка всё равно ставится.
     *
     * @param  Collection<int, EmailMessage>  $letters
     */
    public function settleHistory(Collection $letters): void
    {
        $mirror = app(MailHistoryMirrorService::class);
        foreach ($letters as $m) {
            if ($m->is_history && (! $m->needsBodyFetch() || $mirror->fetchBody($m))) {
                $m->forceFill(['is_history' => false])->save();
            }
        }
    }

    /**
     * Заявка без клиента (восстановлена по нашему запросу поставщику, M-2026-21657):
     * клиентом становится отправитель самого раннего привязанного входящего письма с
     * внешнего адреса, а это письмо — исходным письмом заявки, если исходным стоит не
     * письмо клиента. Организация — штатно по адресу, если ещё не задана. Клиент уже
     * есть — не трогаем.
     *
     * @param  Collection<int, EmailMessage>  $letters
     * @return string|null  адрес нового клиента
     */
    public function adoptClient(Request $request, Collection $letters): ?string
    {
        if (trim((string) $request->client_email) !== '') {
            return null;
        }
        $internal = app(InternalSenderDetector::class);
        $first = $letters
            ->filter(fn (EmailMessage $m) => $m->direction === MailDirection::Inbound
                && trim((string) $m->from_email) !== '' && $internal->detect($m) === null)
            ->sortBy(fn (EmailMessage $m) => $m->sent_at)
            ->first();
        if ($first === null) {
            return null;
        }

        $email = mb_strtolower(trim((string) $first->from_email));
        $attrs = ['client_email' => $email];
        if (trim((string) $request->client_name) === '' && trim((string) $first->from_name) !== '') {
            $attrs['client_name'] = trim((string) $first->from_name);
        }
        $source = $request->email_message_id
            ? EmailMessage::withHistory()->withoutRawSource()->find($request->email_message_id, ['id', 'direction'])
            : null;
        if ($source === null || $source->direction !== MailDirection::Inbound) {
            $attrs['email_message_id'] = $first->id;
        }
        $request->forceFill($attrs)->save();
        app(\App\Services\Clients\RequestOrganizationResolver::class)->attach($request);

        Log::info('MailToRequestLinker: request got its client from a linked letter', [
            'request_id' => $request->id,
            'client_email' => $email,
            'source_email_message_id' => $attrs['email_message_id'] ?? $request->email_message_id,
            'was_source' => $source?->id,
        ]);

        return $email;
    }

    /**
     * Номера из темы и имён вложений: коды заявок (M-2026-21549, MZ2026-00524) и номера
     * 1С из шести цифр, в том числе с ведущими нулями («000372869», «МЗ-372869»).
     *
     * @return array{codes: list<string>, onec: list<string>}
     */
    public static function referencedNumbers(string $text): array
    {
        preg_match_all(RequestCode::REGEX, $text, $c);
        $codes = array_values(array_unique(array_map('mb_strtoupper', $c[0])));
        $rest = (string) preg_replace(RequestCode::REGEX, ' ', $text);
        preg_match_all('/(?<!\d)0*(\d{6})(?!\d)/u', $rest, $n);

        return ['codes' => $codes, 'onec' => array_values(array_unique($n[1]))];
    }

    /**
     * Адреса другой стороны: получатели исходящего, отправитель входящего.
     *
     * @return list<string>
     */
    public static function counterpartyEmails(EmailMessage $message): array
    {
        $list = $message->direction === MailDirection::Outbound
            ? array_merge((array) $message->to_recipients, (array) $message->cc_recipients)
            : [['email' => $message->from_email]];

        return array_values(array_unique(array_filter(array_map(
            fn ($r) => mb_strtolower(trim((string) (is_array($r) ? ($r['email'] ?? '') : $r))),
            $list,
        ))));
    }
}
