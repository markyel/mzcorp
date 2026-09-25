<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Organization;
use App\Models\OrganizationLinkRequest;
use App\Models\OutboundQuote;
use App\Models\Supplier;
use App\Services\Clients\OrganizationLinkGuard;
use App\Services\Clients\OrganizationRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIO;
use Smalot\PdfParser\Parser;

/**
 * Извлечение реквизитов ПОКУПАТЕЛЯ (организации) из внешних КП/счетов (PDF, xls, docx),
 * пойманных в исходящей почте (OutboundQuote). В документах 1С есть блок
 * «Покупатель: <Название>, ИНН …, КПП …, <адрес>» — оттуда тянем
 * Название / ИНН / КПП / адрес и наполняем реестр организаций.
 *
 * Покупатель = ИНН, отличный от нашего (config services.company.inn).
 * Организация апсёртится по ИНН; связывается с контактом (email заявки).
 *
 * Идемпотентно: обработанные OutboundQuote помечаются
 * payload.requisites_extracted=true (повторный прогон их пропускает, можно
 * докручивать частями через --limit).
 *
 *   php artisan clients:extract-requisites               # dry-run (оценка)
 *   php artisan clients:extract-requisites --apply
 *   php artisan clients:extract-requisites --apply --limit=300
 */
class ClientsExtractRequisitesCommand extends Command
{
    protected $signature = 'clients:extract-requisites
        {--apply : Реально писать организации/связи}
        {--limit=0 : Максимум документов за прогон (0 = все необработанные)}
        {--retry-empty : Перепроверить и те, где покупателя не нашли (после правки парсера)}
        {--no-notify : Сомнительные привязки завести без писем менеджерам (перепрогон по истории)}';

    protected $description = 'Достать реквизиты организаций-покупателей из внешних КП/счетов (OutboundQuote)';

    private string $ourInn = '';

    /**
     * Все наши ИНН, от которых мы продаём.
     *
     * Их в документах может быть несколько: счёт физлицу выписывается от
     * второго юрлица (ИП), и покупателем оно не является. Пока проверялся
     * один ИНН, разбор заводил наше же ИП в реестр клиентов и цеплял его к
     * адресам заказчиков — заявки M-2026-12806, 12289, 13489.
     *
     * @var array<int, string>
     */
    private array $ourInns = [];

    /**
     * ИНН покупателя в документе: российский (10/12 цифр) или белорусский
     * УНП (9 цифр). Белорусским клиентам 1С пишет УНП под той же подписью
     * «ИНН 101439542», и прежний шаблон на 10–12 цифр пропускал все их КП
     * и счета — у ООО «ЭкоЛифт» так остались без реквизитов 46 документов.
     */
    private const INN = '(?:ИНН|УНП)\D{0,4}(\d{10,12}|\d{9}(?!\d))';

    /** То же без захвата — для якоря «после ИНН идёт адрес». */
    private const INN_BARE = '(?:ИНН|УНП)\D{0,4}(?:\d{10,12}|\d{9}(?!\d))';

    public function __construct(
        private readonly OrganizationLinkGuard $linkGuard,
        private readonly OrganizationRegistryService $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $this->ourInn = preg_replace('/\D+/', '', (string) config('services.company.inn', '')) ?? '';
        $this->ourInns = array_values(array_unique(array_filter(array_merge(
            [$this->ourInn],
            (array) config('services.company.own_inns', []),
        ))));

        // По умолчанию берём только неразобранные. `--retry-empty` добавляет
        // те, где покупателя не нашли: после правки парсера их надо прогнать
        // заново, а уже опознанные — не трогать.
        $base = OutboundQuote::query()
            ->with('request:id,client_email')
            ->whereNotNull('email_attachment_id')
            ->when(
                (bool) $this->option('retry-empty'),
                fn ($q) => $q->whereRaw("(payload->>'requisites_buyer_inn') IS NULL"),
                fn ($q) => $q->whereRaw("(payload->>'requisites_extracted') IS NULL"),
            );

        $pending = (clone $base)->count();
        $this->info(sprintf('Необработанных документов: %d. Mode: %s.', $pending, $apply ? 'APPLY' : 'DRY-RUN'));
        if (! $apply) {
            $this->warn('DRY-RUN — запусти с --apply, чтобы извлечь и записать.');

            return self::SUCCESS;
        }

        $stats = ['processed' => 0, 'with_buyer' => 0, 'orgs_new' => 0, 'links' => 0, 'requests_linked' => 0, 'no_text' => 0];

        (clone $base)->orderBy('id')->chunkById($limit > 0 ? min(200, $limit) : 200, function ($chunk) use (&$stats, $limit) {
            foreach ($chunk as $q) {
                if ($limit > 0 && $stats['processed'] >= $limit) {
                    return false;
                }
                $this->processOne($q, $stats);
            }

            return ! ($limit > 0 && $stats['processed'] >= $limit);
        });

        $this->newLine();
        $this->table(['metric', 'value'], collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());

        return self::SUCCESS;
    }

    private function processOne(OutboundQuote $q, array &$stats): void
    {
        $stats['processed']++;
        $text = $this->documentText($q->email_attachment_id);
        $registryVerdict = null;

        if ($text === null) {
            $stats['no_text']++;
        } else {
            $buyer = $this->parseBuyer($text);
            if ($buyer['inn'] !== null) {
                $stats['with_buyer']++;

                // ИНН из документа — только повод спросить реестр. Название,
                // КПП и адрес берём из выписки: разборщик их то обрезает, то
                // склеивает с артикулом, то путает колонки.
                $res = $this->registry->resolveForIngest($buyer['inn'], $buyer['name']);
                $registryVerdict = $res['status'];

                if ($res['status'] === 'unavailable') {
                    // Реестр молчит — документ не помечаем разобранным: следующий
                    // прогон спросит снова. Лучше опоздать с организацией, чем
                    // завести её по догадке разборщика.
                    $stats['registry_unavailable'] = ($stats['registry_unavailable'] ?? 0) + 1;
                    $stats['processed']--;

                    return;
                }

                if ($res['status'] === 'ok') {
                    $org = $res['org'];
                    if ($res['created']) {
                        $stats['orgs_new']++;
                    }
                    // Выписка про ИП КПП не даёт, про адрес — почти всегда даёт.
                    // Из документа дописываем только то, чего в реестре нет.
                    if (trim((string) ($org->kpp ?? '')) === '' && $buyer['kpp'] && strlen((string) $org->inn) === 10) {
                        $org->kpp = $buyer['kpp'];
                    }
                    if (trim((string) ($org->address ?? '')) === '' && $buyer['address']) {
                        $org->address = $buyer['address'];
                    }
                    $org->save();
                    foreach ($this->documentRecipients($q) as $email) {
                        $this->linkEmail($org, $email, $q, $stats);
                    }
                } else {
                    // not_found — такого ИНН нет в ЕГРЮЛ/ЕГРИП; ours — это мы сами.
                    // В обоих случаях организацию не заводим и к адресу не цепляем.
                    $stats['registry_'.$res['status']] = ($stats['registry_'.$res['status']] ?? 0) + 1;
                }
            }
        }

        // Пометить обработанным. Найденный ИНН тоже пишем: по нему видно, какие
        // документы стоит перепроверить после правки парсера (--retry-empty).
        $payload = is_array($q->payload) ? $q->payload : [];
        $payload['requisites_extracted'] = true;
        if (isset($buyer) && $buyer['inn'] !== null && $registryVerdict === 'ok') {
            $payload['requisites_buyer_inn'] = $buyer['inn'];
        }
        if ($registryVerdict !== null) {
            $payload['requisites_registry'] = $registryVerdict;
        }
        $q->forceFill(['payload' => $payload])->save();
    }

    /**
     * Парсинг блока «Покупатель: …, ИНН …, КПП …, адрес».
     *
     * @return array{name: ?string, inn: ?string, kpp: ?string, address: ?string}
     */
    private function parseBuyer(string $text): array
    {
        $res = ['name' => null, 'inn' => null, 'kpp' => null, 'address' => null];
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($flat === '') {
            return $res;
        }

        // 1) Чёткий блок «Покупатель|Заказчик: <Название>, ИНН …, КПП …, <адрес>».
        // «Покупатель» — счета 1С, «Заказчик» — наши КП: реквизиты там тоже есть.
        if (preg_match('/(?:Покупатель|Заказчик)\s*:?\s*([^,]{2,90})(.{0,200})/iu', $flat, $m)
            && preg_match('/'.self::INN.'/iu', $m[2], $mi)
            && ! $this->isOurs($mi[1])) {
            $res['inn'] = $mi[1];
            $nm = $this->cleanName($m[1]);
            // Отбраковываем «артикульные» имена (6311-2RS и т.п.): пусть имя
            // придёт из более чистого документа этого ИНН. ИНН/КПП/адрес — берём.
            $res['name'] = $this->isJunkName($nm) ? null : $nm;
            if (preg_match('/КПП\D{0,4}(\d{9})/iu', $m[2], $mk)) {
                $res['kpp'] = $mk[1];
            }
            $res['address'] = self::addressAfterIds($m[2]);

            return $res;
        }

        // 2) В КП подпись «Заказчик:» стоит в своей колонке, и после
        // схлопывания пробелов она оказывается ПОСЛЕ названия:
        // «… ООО«Техкомплект», ИНН 7717296192, КПП … Заказчик: тел.: …».
        // Ловим по самому ИНН, а название берём слева от него — но только
        // если оно похоже на организацию (есть форма собственности).
        // Без этой проверки прежний «голый ИНН» давал 17 мусорных имён из 18.
        foreach (self::allInns($flat) as [$inn, $offset]) {
            if ($this->isOurs($inn)) {
                continue;
            }
            // Смещение указывает на цифры, а перед ними стоит сам маркер
            // «ИНН» — снимаем его, иначе названием окажется он же.
            $before = mb_substr($flat, max(0, $offset - 140), min($offset, 140));
            $before = preg_replace('/[\s,;:]*(?:ИНН|УНП)\D{0,4}$/iu', '', $before) ?? $before;
            if (! preg_match('/([^,;:|]{2,90})\s*,?\s*$/u', $before, $mn)) {
                continue;
            }
            // Отрезаем всё до формы собственности: слева могли остаться хвосты
            // соседней колонки («… info@mylift.ru ООО«Техкомплект»»).
            $name = self::fromCompanyForm($this->cleanName($mn[1]));
            if (! self::looksLikeCompany($name) || $this->isJunkName($name)) {
                continue;
            }

            $tail = mb_substr($flat, $offset, 220);
            $res['inn'] = $inn;
            $res['name'] = $name;
            if (preg_match('/КПП\D{0,4}(\d{9})/iu', $tail, $mk)) {
                $res['kpp'] = $mk[1];
            }
            // Хвост начинается с самих цифр ИНН — возвращаем ему подпись.
            $res['address'] = self::addressAfterIds('ИНН '.$tail);

            return $res;
        }

        // Ни блока, ни организации рядом с ИНН — это не наш клиентский
        // документ (входящий счёт поставщика, банковская выписка, инвойс
        // иностранцу), организацию не создаём.
        return $res;
    }

    /**
     * Все ИНН текста со смещениями.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private static function allInns(string $flat): array
    {
        if (! preg_match_all('/'.self::INN.'/iu', $flat, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $hit) {
            // preg смещения байтовые — переводим в символьные для mb_substr.
            $out[] = [$hit[0], mb_strlen(substr($flat, 0, $hit[1]))];
        }

        return $out;
    }

    /** Формы собственности — по ним опознаём начало названия организации. */
    private const COMPANY_FORMS = 'ООО|ОАО|ЗАО|ПАО|АО|НАО|ИП|ФГУП|ГУП|МУП|НКО|ТСЖ|УК|СНТ|ЧОУ|ФГБУ|ГБУ|МБУ';

    /** Оставить название с формы собственности: «…@mylift.ru ООО«Х»» → «ООО«Х»». */
    public static function fromCompanyForm(string $name): string
    {
        if (preg_match('/((?:'.self::COMPANY_FORMS.')\W.*)$/u', $name, $m)) {
            return trim($m[1], " ,;:\t");
        }

        return $name;
    }

    /**
     * Адрес — то, что идёт после ПОСЛЕДНЕГО из «ИНН …» / «КПП …».
     *
     * Прежний шаблон брал текст после первого совпавшего маркера, а первым в
     * строке стоит ИНН — так в адрес 944 карточек попало «КПП 771401001, …».
     * Жадное «.*» в начале доводит до последнего маркера.
     */
    public static function addressAfterIds(string $s): ?string
    {
        if (! preg_match('/^.*(?:КПП\D{0,4}\d{9}|'.self::INN_BARE.')\s*,?\s*(.+)$/isu', $s, $m)) {
            return null;
        }
        $address = self::cutAddress($m[1]);

        return $address !== '' ? $address : null;
    }

    /**
     * Адрес обрывается там, где начинается следующая колонка документа:
     * «…ком. 12, Заказчик: тел.: …» — всё после подписи уже не адрес.
     */
    public static function cutAddress(string $raw): string
    {
        $cut = preg_split('/\s*(?:Заказчик|Покупатель|Поставщик|Исполнитель|Грузополучатель|Карта клиента|Ответственный|Основание|Внимание|Счет действителен|Содержание запроса|тел\.?:|e-?mail)/iu', trim($raw))[0] ?? $raw;

        return trim(mb_substr(trim($cut), 0, 160), ' ,;:');
    }

    /** Название похоже на организацию: есть форма собственности. */
    public static function looksLikeCompany(string $name): bool
    {
        return preg_match(
            '/(^|\W)(ООО|ОАО|ЗАО|ПАО|АО|НАО|ИП|ФГУП|ГУП|МУП|НКО|ТСЖ|УК|СНТ|ЧОУ|ФГБУ|ГБУ|МБУ)(\W|$)|общество\s+с\s+ограниченной|индивидуальный\s+предприниматель/iu',
            $name,
        ) === 1;
    }

    private function cleanName(string $s): string
    {
        $s = preg_replace('/^\s*Покупатель\s*:?\s*/iu', '', trim($s)) ?? $s;

        return trim($s, " ,;:\t\n");
    }

    /**
     * Имя похоже на мусор/артикул, а не на название организации:
     *  - есть кавычки «…»/"…" → настоящее название, НЕ мусор;
     *  - 3+ цифры подряд без кавычек («ип 6311-2RS») → артикул, мусор;
     *  - нет ни одного слова из ≥3 букв (кроме орг-формы) → мусор.
     */
    private function isJunkName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }
        if (preg_match('/[«»"„“]/u', $name) === 1) {
            return false;
        }
        if (preg_match('/\d{3,}/', $name) === 1) {
            return true;
        }
        $woForm = preg_replace('/^(?:ООО|ОАО|ЗАО|ПАО|НАО|АО|ИП|НКО|ФГУП|МУП|ГУП|ГБУ|МБУ|АНО|ТСЖ|СНТ)\b/iu', '', $name) ?? $name;

        return preg_match('/\p{L}{3,}/u', $woForm) !== 1;
    }

    /** Наш ли это ИНН — продавец, а не покупатель. */
    private function isOurs(?string $inn): bool
    {
        $inn = preg_replace('/\D+/', '', (string) $inn) ?? '';

        return $inn !== '' && in_array($inn, $this->ourInns, true);
    }

    /**
     * Кому на самом деле ушёл документ — к этим адресам и цепляем покупателя.
     *
     * Раньше брался e-mail заявки, а письмо к заявке бывает приклеено чужое:
     * КП 358863 для ЛИФТРЕМОНТа ушло zelenkova@liftremont.ru, но лежало в
     * заявке ima@service-cl.ru — и ЛИФТРЕМОНТ повис на КОМБОЛИФТ СЕРВИС.
     * По заявкам, пересланным нашим сотрудником, e-mail заявки — вообще наш.
     *
     * Внешние получатели письма (To/Cc) — они и есть заказчик. Если внешних
     * нет (менеджер переслал счёт коллеге), остаётся e-mail заявки. Наши
     * домены не привязываются никогда.
     *
     * @return array<int, string>
     */
    private function documentRecipients(OutboundQuote $q): array
    {
        $message = $q->email_message_id ? EmailMessage::find($q->email_message_id) : null;
        $recipients = collect(array_merge((array) ($message?->to_recipients ?? []), (array) ($message?->cc_recipients ?? [])))
            ->map(fn ($r) => mb_strtolower(trim((string) (is_array($r) ? ($r['email'] ?? '') : $r))))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! $this->isInternalEmail($e))
            // Счёт клиента менеджер пересылает и поставщику (unisystem.si,
            // paulschaab.de, liftway.ru) — поставщик не заказчик.
            ->reject(fn ($e) => $this->isSupplierEmail($e))
            ->unique()->values()->all();
        if ($recipients !== []) {
            return $recipients;
        }

        $client = mb_strtolower(trim((string) (optional($q->request)->client_email ?? '')));

        return filter_var($client, FILTER_VALIDATE_EMAIL) && ! $this->isInternalEmail($client) ? [$client] : [];
    }

    /** @var array<string, bool> */
    private array $supplierCache = [];

    /** Адрес поставщика: сам ящик в справочнике или корпоративный домен поставщика. */
    private function isSupplierEmail(string $email): bool
    {
        return $this->supplierCache[$email] ??= (function () use ($email) {
            $domain = mb_strtolower((string) substr((string) strrchr($email, '@'), 1));
            $free = in_array($domain, (array) config('services.mail.free_mail_domains', []), true);

            return Supplier::query()
                ->where(function ($q) use ($email, $domain, $free) {
                    $q->whereRaw('lower(email) = ?', [$email]);
                    if (! $free && $domain !== '') {
                        $q->orWhereRaw('lower(email) LIKE ?', ['%@'.$domain])
                            ->orWhereRaw('lower(domain) = ?', [$domain]);
                    }
                })
                ->exists();
        })();
    }

    /** Адрес на нашем домене (services.mail.internal_domains): сотрудник или технический ящик. */
    private function isInternalEmail(string $email): bool
    {
        $domain = mb_strtolower((string) substr((string) strrchr($email, '@'), 1));
        $internal = array_map(fn ($d) => mb_strtolower(trim((string) $d)), (array) config('services.mail.internal_domains', []));

        return $domain !== '' && in_array($domain, $internal, true);
    }

    /**
     * Привязка через OrganizationLinkGuard: реквизиты известного контрагента
     * на чужом адресе не привязываются, а уходят менеджеру на подтверждение.
     * Закреплённый адрес другими юрлицами не обогащается.
     */
    private function linkEmail(Organization $org, string $email, OutboundQuote $q, array &$stats): void
    {
        $res = $this->linkGuard->link($org, $email, [
            'source' => OrganizationLinkRequest::SOURCE_OUTBOUND_QUOTE,
            'outbound_quote' => $q,
        ], notify: ! $this->option('no-notify'));

        match ($res['status']) {
            'linked' => $stats['links']++,
            'pinned' => $stats['pinned_skipped'] = ($stats['pinned_skipped'] ?? 0) + 1,
            'pending' => $stats['links_held'] = ($stats['links_held'] ?? 0) + 1,
            default => null,
        };
        $stats['requests_linked'] += $res['requests_linked'];
    }

    /**
     * Текст документа. Кроме PDF менеджеры отправляют счета и КП прямо
     * из 1С в .xls (около трёхсот документов), изредка — .xlsx и .docx;
     * блок «Покупатель: …, ИНН …» в них тот же.
     */
    private function documentText(int $attId): ?string
    {
        $att = EmailAttachment::find($attId);
        if (! $att || ! $att->file_path) {
            return null;
        }
        $disk = $att->disk ?: 'local';
        $ext = strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'xls', 'xlsx', 'docx'], true) || ! Storage::disk($disk)->exists($att->file_path)) {
            return null;
        }
        $path = Storage::disk($disk)->path($att->file_path);

        try {
            $text = match ($ext) {
                'pdf' => (new Parser)->parseFile($path)->getText(),
                'docx' => self::docxText($path),
                default => self::spreadsheetText($path),
            };
        } catch (\Throwable $e) {
            return null;
        }

        return trim((string) $text) !== '' ? $text : null;
    }

    /** Ячейки листов построчно: непустые через пробел, строки — переводом. */
    private static function spreadsheetText(string $path): string
    {
        $reader = SpreadsheetIO::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $lines = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, false, false, false) as $row) {
                $cells = array_filter(array_map(fn ($v) => trim((string) $v), $row), fn ($v) => $v !== '');
                if ($cells !== []) {
                    $lines[] = implode(' ', $cells);
                }
            }
        }
        $book->disconnectWorksheets();

        return implode("\n", $lines);
    }

    /** Текст .docx: абзацы и ячейки таблиц из word/document.xml. */
    private static function docxText(string $path): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags(str_replace(['</w:p>', '</w:tc>'], ["\n", ' '], $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
