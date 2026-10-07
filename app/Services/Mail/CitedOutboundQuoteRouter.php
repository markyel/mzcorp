<?php

namespace App\Services\Mail;

use App\Enums\RequestStatus;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Request\AttentionService;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Детектор «клиент цитирует наш КП/счёт».
 *
 * Клиент присылает письмо со ссылкой на наш ранее выданный КП (или счёт) —
 * явно называет номер («счёт № 10432», «КП 364274», «МЗ-10551», наша тема
 * «369647 Re: …») ИЛИ прикладывает наш PDF. Не важно, на какой e-mail был
 * выдан КП (его мог переслать коллега). Мы находим заявку, по которой выдан
 * цитируемый документ, и маршрутизируем письмо туда со статусом «ждёт счёт»
 * вместо создания новой заявки.
 *
 * Кандидаты — только ЯВНЫЕ ссылки (см. константы ниже), и они ПЕРЕСЕКАЮТСЯ с
 * реальными `outbound_quotes.document_number`. Голое число без контекста,
 * число в имени файла или на картинке — не сигнал: оно имеет право случайно
 * совпасть с номером нашего документа. Несколько КП → берём с наибольшей суммой.
 *
 * ВАЖНО: просьбу о счёте (invoice_intent) ищем только в СОБСТВЕННОМ тексте
 * клиента, без цитаты нашего письма. Кейс M-2026-12166: клиент ответил «а с
 * резьбой М10 есть?» на наше напоминание, в цитате которого были номер КП
 * 364274 и «перейти к выставлению счёта» → роутер «нашёл» запрос счёта в
 * нашем же тексте и увёл заявку в «ждёт счёт». Без invoice_intent MailRouter
 * привязывает письмо к заявке КП (и реанимирует закрытую), но статус не
 * трогает.
 *
 * PDF-текст берём напрямую Smalot'ом (дёшево, без Vision/рендера страниц).
 */
class CitedOutboundQuoteRouter
{
    /**
     * Политика (2026-10-07): номер нашего документа ищем ТОЛЬКО там, где клиент
     * на него явно ссылается. Голое число в тексте, число в имени файла или на
     * картинке имеет право случайно совпасть с номером нашего КП/счёта (с
     * сентября 2026 номера пятизначные — коллизии регулярные: индекс в подписи
     * M-2026-3642, хвост UUID фото M-2026-17776, артикул ВП73-10432 M-2026-18326).
     *
     * Сигналы:
     *   1. Слово-документ + номер: «счёт № 10432», «по счету 10432», «КП 364274»,
     *      «коммерческое предложение №369647 от …», «invoice 10432».
     *   2. Наш префикс: «МЗ-10551» (так называются наши файлы и так мы пишем в теле).
     *   3. Наш формат темы: номер первым словом («369647 Re: Заявка», «[369796]»),
     *      в т.ч. в строке «Тема:/Subject:» цитаты.
     *   4. Приложен НАШ документ: PDF, в тексте которого заголовок «Счет на оплату
     *      № N» / «Коммерческое предложение №N» И наш ИНН (services.company.own_inns).
     *      Картинки (фото/сканы) не смотрим вовсе; имена не-картинок — только по п.2.
     */
    private const DOC_REF_RE = '/(?<![\p{L}\d])(?:сч[её]т(?:а|у|ом|е|ы|ов|ах)?|сч[её]т-фактур\p{L}*|с\/ф|кп|коммерческ\p{L}+\s+предложени\p{L}+|предложени\p{L}+|оферт\p{L}*|invoice|proforma|инвойс\p{L}*|quot(?:e|ation))\s*(?:на\s+оплату\s*)?(?:от\s+\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\s*(?:г\.?\s*)?)?(?:№|#|no\.?|n|номер)?\s*(?:мз-?\s?)?(\d{5,8})(?!\d)/iu';

    /** «МЗ-10551» / «МЗ 369647» — наш префикс документов (файлы и тело наших писем). */
    private const OUR_PREFIX_RE = '/(?<![\p{L}\d])мз-?\s?(\d{5,8})(?!\d)/iu';

    /** Тема в нашем формате: номер первым словом после Re:/Fwd:, возможно в скобках. */
    private const SUBJECT_LEAD_RE = '/^\s*(?:(?:re|fwd?|fw|aw|sv|отв)\s*:\s*)*\[?(\d{5,8})\]?(?!\d)/iu';

    /** Та же тема в заголовке цитаты/пересылки внутри тела. */
    private const QUOTED_SUBJECT_RE = '/^\s*>?\s*(?:тема|subject)\s*:\s*(?:(?:re|fwd?|fw|aw|sv|отв)\s*:\s*)*\[?(\d{5,8})\]?(?!\d)/imu';

    /** Заголовок нашего документа в тексте PDF (1С). */
    private const OUR_PDF_HEADER_RE = '/(?:сч[её]т\s+на\s+оплату|коммерческое\s+предложение)\s*№\s*(\d{5,8})(?!\d)/iu';

    /**
     * UUID и длинные hex-хэши (имена фото с телефона, cid картинок) — вырезаем
     * до поиска номеров. Кейс M-2026-17776: фото «c7b0d328-…-4f49dea10304.jpg»
     * от другого клиента дало «10304» = номер нашего КП, а номер из вложения
     * считается надёжным → письмо приклеилось к чужой закрытой сделке.
     */
    private const HASH_RE = '/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}|(?<![0-9a-z])(?=[0-9a-f]*[a-f])(?=[0-9a-f]*\d)[0-9a-f]{12,}(?![0-9a-z])/i';
    /**
     * Наши коды — номер заявки «M-2026-10317» и артикул каталога «M10317».
     * Счета с сентября 2026 пятизначные (10246…), и хвосты этих кодов совпадают
     * с номерами чужих счетов. Вырезаем до поиска номеров.
     */
    private const OWN_CODE_RE = '/(?<![\p{L}\d])[mм]-\d{4}-\d{3,6}(?!\d)|(?<![\p{L}\d])[mм]\d{4,6}(?!\d)/iu';
    /**
     * Артикул с буквенно-цифровым префиксом через дефис: «ВП73-10432»,
     * «FAA24-350BL2», «КС00-002365». Число после дефиса — часть артикула, а не
     * номер документа. Кейс M-2026-18326: клиент другой компании попросил счёт
     * на «Выключатель ВП73-10432», а 10432 — номер нашего КП по чужой заявке
     * → письмо и позиция приклеились к ней.
     *
     * Префикс только «буквы+цифры» (ВП73, КС00): чисто буквенный «МЗ-364274» —
     * это наши документы, его не трогаем. Чисто цифровой «21-10432» тоже
     * остаётся — числа через дефис встречаются и в цитатах наших писем.
     */
    private const ARTICLE_RE = '/(?<![\p{L}\d])\p{L}{1,6}\d{1,4}-\d{5,8}(?![\p{L}\d])/u';
    /**
     * Ссылки: слаги каталогов («…/mikropereklyuchatel-vp-73-21-10432.html»),
     * метки ysclid/utm — числа внутри URL никогда не номер нашего документа.
     */
    private const URL_RE = '~https?://\S+~iu';

    private const MAX_PDF_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly EmailTextCleanerService $cleaner = new EmailTextCleanerService,
    ) {}

    /**
     * @return array{request: Request, document_number: string, total: float, source: string, invoice_intent: bool}|null
     */
    public function detect(EmailMessage $message): ?array
    {
        // Явные ссылки на наш документ ищем во ВСЁМ тексте (в т.ч. в цитате
        // нашего письма — клиент часто отвечает «выставите счёт» под нашим КП,
        // и номер только там): это нужно для ПРИВЯЗКИ письма к заявке. А вот
        // просьба о счёте (invoice_intent) — только из собственного текста клиента.
        [$candidates, $hasAttachmentSource] = $this->collectCandidates($message);
        if ($candidates === []) {
            return null;
        }

        $invoiceIntent = $this->hasInvoiceIntent((string) $message->subject, $this->ownBodyText($message));

        $rows = DB::table('outbound_quotes')
            ->whereIn('document_number', $candidates)
            ->whereNotNull('request_id')
            ->get(['request_id', 'document_number', 'total_amount']);
        if ($rows->isEmpty()) {
            return null;
        }

        // Несколько процитированных КП → берём с наибольшей суммой.
        $best = $rows->sortByDesc(fn ($r) => (float) ($r->total_amount ?? 0))->first();
        $request = Request::find($best->request_id);
        if (! $request) {
            return null;
        }

        Log::info('CitedOutboundQuoteRouter: matched cited quote', [
            'email_message_id' => $message->id,
            'document_number' => $best->document_number,
            'request_id' => $request->id,
            'candidates_matched' => $rows->pluck('document_number')->all(),
        ]);

        return [
            'request' => $request,
            'document_number' => (string) $best->document_number,
            'total' => (float) ($best->total_amount ?? 0),
            'source' => $hasAttachmentSource ? 'attachment' : 'body',
            'invoice_intent' => $invoiceIntent,
        ];
    }

    /**
     * Собственный текст клиента: без цитаты нашего письма («… написал(а):»,
     * «>»-блок) и без блока пересылки, если у клиента есть своя преамбула.
     * Чистый метод — покрыт unit-тестом.
     */
    public function ownBodyText(EmailMessage $message): string
    {
        // Единый владелец понятия — EmailTextCleanerService::clientOwnText
        // (те же правила: срез любой цитаты + блок пересылки при преамбуле).
        return $this->cleaner->clientOwnText($message);
    }

    /**
     * Есть ли в собственном тексте клиента (или теме) просьба о счёте либо
     * намерение оплатить. Единый владелец понятия — InvoiceMentionMatcher.
     * Раньше здесь была широкая regex (любое «счёт/оплат/выстав»), и вопрос
     * «когда получим по счёту № N» уводил заявку в «ждёт счёт»; реплей
     * 2026-09-09 по 166 письмам с цитатой КП: 33 таких ложных срабатывания.
     */
    public function hasInvoiceIntent(string $subject, string $ownBody): bool
    {
        return (new InvoiceMentionMatcher)->requestsInvoiceOrIntendsToPay($subject."\n".$ownBody);
    }

    /**
     * Применить маршрут «клиент процитировал наш КП → запрос счёта»: привязать
     * письмо к заявке КП, реанимировать её если закрыта потерей, перевести в
     * «ждёт счёт» (AwaitingInvoice), записать аудит. Идемпотентно. Перенесено
     * из MailRouter без изменений (2026-09-09).
     *
     * @param  array{request: Request, document_number: string, total: float, source: string, invoice_intent?: bool}  $cited
     */
    public function applyInvoiceRequest(EmailMessage $message, array $cited): Request
    {
        $request = $cited['request'];
        $docNo = $cited['document_number'];

        if ($message->related_request_id !== $request->id) {
            $message->forceFill(['related_request_id' => $request->id])->save();
        }

        // Заявка закрыта потерей (клиент молчал, теперь вернулся за счётом) →
        // реанимируем. reanimate() работает только из closed_lost.
        if ($request->status === RequestStatus::ClosedLost) {
            try {
                $request = app(RequestStateService::class)->reanimate(
                    $request,
                    null,
                    $message,
                    true,
                    'reanimate_from_cited_quote',
                    sprintf('Клиент прислал запрос счёта по КП %s — реанимация', $docNo),
                );
            } catch (\Throwable $e) {
                Log::warning('MailRouter: cited-quote reanimate failed (non-fatal)', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Перевод в «ждёт счёт» — если уже не на этой/более поздней вехе И
        // клиент в собственном тексте действительно просит счёт/оплату
        // (invoice_intent). Цитирует КП с вопросом («а с резьбой М10 есть?»,
        // M-2026-12166) — письмо привязываем, статус не трогаем.
        $skip = [
            RequestStatus::AwaitingInvoice,
            RequestStatus::Invoiced,
            RequestStatus::Paid,
            RequestStatus::ClosedWon,
        ];
        $invoiceIntent = (bool) ($cited['invoice_intent'] ?? true);
        if (! $invoiceIntent) {
            Log::info('MailRouter: cited quote without invoice intent — linked, status unchanged', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'document_number' => $docNo,
            ]);
        }
        if ($invoiceIntent && ! in_array($request->status, $skip, true)) {
            $from = $request->status->value;
            $request->status = RequestStatus::AwaitingInvoice;
            $request->save();
            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $from,
                'to_status' => RequestStatus::AwaitingInvoice->value,
                'by_user_id' => null,
                'event' => 'invoice_requested_cited_quote',
                'comment' => sprintf('Клиент процитировал КП %s и запросил счёт (маршрут по номеру КП, %s)', $docNo, $cited['source']),
                'payload' => [
                    'document_number' => $docNo,
                    'source' => $cited['source'],
                    'email_message_id' => $message->id,
                ],
            ]);
            try {
                app(AttentionService::class)->recompute($request->fresh());
            } catch (\Throwable $e) {
                Log::info('MailRouter: attention recompute after cited-quote route failed (non-fatal)', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('MailRouter: cited-quote invoice-request routed', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'document_number' => $docNo,
            'source' => $cited['source'],
        ]);

        return $request->fresh();
    }

    /**
     * Номера наших документов, на которые письмо ссылается явно (см. политику
     * у констант). Картинки игнорируются целиком.
     *
     * @return array{0: array<int,string>, 1: bool} [номера, приложен ли наш документ]
     */
    private function collectCandidates(EmailMessage $message): array
    {
        $numbers = [];

        $subject = $this->scrub((string) $message->subject);
        $numbers = array_merge($numbers, $this->matchAll(self::SUBJECT_LEAD_RE, $subject));
        $numbers = array_merge($numbers, $this->matchAll(self::DOC_REF_RE, $subject));
        $numbers = array_merge($numbers, $this->matchAll(self::OUR_PREFIX_RE, $subject));

        $body = $this->scrub((string) $message->body_plain);
        $numbers = array_merge($numbers, $this->matchAll(self::DOC_REF_RE, $body));
        $numbers = array_merge($numbers, $this->matchAll(self::OUR_PREFIX_RE, $body));
        $numbers = array_merge($numbers, $this->matchAll(self::QUOTED_SUBJECT_RE, $body));

        $hasOurDocument = false;
        foreach ($message->attachments as $att) {
            if (str_starts_with(mb_strtolower((string) $att->mime_type), 'image/')) {
                continue; // фото/сканы: число на картинке — не ссылка на документ
            }
            // Имя файла — только наш префикс («Счет МЗ-10551 от ….pdf»).
            $fromName = $this->matchAll(self::OUR_PREFIX_RE, $this->scrub((string) $att->filename));
            if ($fromName !== []) {
                $numbers = array_merge($numbers, $fromName);
                $hasOurDocument = true;
            }
            $fromPdf = $this->ourDocumentNumbers($this->attachmentPdfText($att));
            if ($fromPdf !== []) {
                $numbers = array_merge($numbers, $fromPdf);
                $hasOurDocument = true;
            }
        }

        return [array_values(array_unique($numbers)), $hasOurDocument];
    }

    /**
     * Номера из текста PDF, если это НАШ документ: заголовок 1С
     * («Счет на оплату № N» / «Коммерческое предложение №N») и наш ИНН.
     * Чужой счёт с таким же заголовком, но без нашего ИНН — не сигнал.
     *
     * @return list<string>
     */
    private function ourDocumentNumbers(string $pdfText): array
    {
        if ($pdfText === '') {
            return [];
        }
        $digits = (string) preg_replace('/\s+/u', '', $pdfText);
        $isOurs = false;
        foreach ((array) config('services.company.own_inns', []) as $inn) {
            if ((string) $inn !== '' && str_contains($digits, (string) $inn)) {
                $isOurs = true;
                break;
            }
        }
        if (! $isOurs) {
            return [];
        }

        return $this->matchAll(self::OUR_PDF_HEADER_RE, $pdfText);
    }

    /** @return list<string> первая группа каждого совпадения */
    private function matchAll(string $re, string $text): array
    {
        if ($text === '' || ! preg_match_all($re, $text, $m)) {
            return [];
        }

        return array_values(array_filter($m[1] ?? [], fn ($v) => $v !== ''));
    }

    /**
     * Убрать из текста всё, что похоже на число, но номером документа быть не
     * может: ссылки, UUID/хэши, наши коды, артикулы. Порядок важен: ссылка
     * вырезается целиком до того, как её слаг разберут на артикулы.
     */
    private function scrub(string $text): string
    {
        return (string) preg_replace(
            [self::URL_RE, self::HASH_RE, self::OWN_CODE_RE, self::ARTICLE_RE],
            ' ',
            $text,
        );
    }

    /** Текст-слой PDF-вложения (Smalot). '' если не PDF/большой/сбой. */
    private function attachmentPdfText(EmailAttachment $att): string
    {
        $mime = (string) $att->mime_type;
        $fn = mb_strtolower((string) $att->filename);
        $isPdf = str_contains($mime, 'pdf') || str_ends_with($fn, '.pdf');
        if (! $isPdf || ! $att->file_path) {
            return '';
        }
        try {
            $path = Storage::disk($att->disk ?: 'local')->path($att->file_path);
            if (! is_file($path) || filesize($path) > self::MAX_PDF_BYTES) {
                return '';
            }

            return (string) (new Parser)->parseFile($path)->getText();
        } catch (\Throwable $e) {
            Log::warning('CitedOutboundQuoteRouter: pdf text extract failed (non-fatal)', [
                'attachment_id' => $att->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
