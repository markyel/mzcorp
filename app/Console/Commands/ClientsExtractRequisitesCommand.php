<?php

namespace App\Console\Commands;

use App\Models\ClientContact;
use App\Models\EmailAttachment;
use App\Models\Organization;
use App\Models\OutboundQuote;
use App\Services\Clients\RequestOrganizationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Извлечение реквизитов ПОКУПАТЕЛЯ (организации) из PDF внешних КП/счетов,
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
        {--retry-empty : Перепроверить и те, где покупателя не нашли (после правки парсера)}';

    protected $description = 'Достать реквизиты организаций-покупателей из PDF внешних КП/счетов (OutboundQuote)';

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

    public function __construct(private readonly RequestOrganizationResolver $orgResolver)
    {
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
        $text = $this->pdfText($q->email_attachment_id);

        if ($text === null) {
            $stats['no_text']++;
        } else {
            $buyer = $this->parseBuyer($text);
            if ($buyer['inn'] !== null) {
                $stats['with_buyer']++;
                $org = Organization::firstOrNew(['inn' => $buyer['inn']]);
                if (! $org->exists) {
                    $stats['orgs_new']++;
                }
                // Перезаписываем имя, если текущее пустое / плейсхолдер «ИНН N» /
                // мусорное (артикул) — хорошее имя из чистого документа важнее.
                $cur = (string) ($org->name ?? '');
                $replaceable = trim($cur) === ''
                    || preg_match('/^ИНН \d+$/u', $cur) === 1
                    || $this->isJunkName($cur);
                if ($replaceable && $buyer['name']) {
                    $org->name = $buyer['name'];
                }
                if (trim((string) ($org->kpp ?? '')) === '' && $buyer['kpp']) {
                    $org->kpp = $buyer['kpp'];
                }
                if (trim((string) ($org->address ?? '')) === '' && $buyer['address']) {
                    $org->address = $buyer['address'];
                }
                if (trim((string) ($org->name ?? '')) === '') {
                    $org->name = 'ИНН '.$buyer['inn'];
                }
                $org->save();
                $this->linkEmail($org, (string) (optional($q->request)->client_email ?? ''), $stats);
            }
        }

        // Пометить обработанным. Найденный ИНН тоже пишем: по нему видно, какие
        // документы стоит перепроверить после правки парсера (--retry-empty).
        $payload = is_array($q->payload) ? $q->payload : [];
        $payload['requisites_extracted'] = true;
        if (isset($buyer) && $buyer['inn'] !== null) {
            $payload['requisites_buyer_inn'] = $buyer['inn'];
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
            && preg_match('/ИНН\D{0,4}(\d{10,12})/iu', $m[2], $mi)
            && ! $this->isOurs($mi[1])) {
            $res['inn'] = $mi[1];
            $nm = $this->cleanName($m[1]);
            // Отбраковываем «артикульные» имена (6311-2RS и т.п.): пусть имя
            // придёт из более чистого документа этого ИНН. ИНН/КПП/адрес — берём.
            $res['name'] = $this->isJunkName($nm) ? null : $nm;
            if (preg_match('/КПП\D{0,4}(\d{9})/iu', $m[2], $mk)) {
                $res['kpp'] = $mk[1];
            }
            if (preg_match('/(?:КПП\D{0,4}\d{9}|ИНН\D{0,4}\d{10,12})\s*,?\s*(.+)$/iu', $m[2], $ma)) {
                $res['address'] = trim(mb_substr(trim($ma[1]), 0, 160), ' ,;');
            }

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
            $before = preg_replace('/[\s,;:]*ИНН\D{0,4}$/iu', '', $before) ?? $before;
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
            if (preg_match('/(?:КПП\D{0,4}\d{9}|ИНН\D{0,4}\d{10,12})\s*,?\s*(.+)$/iu', $tail, $ma)) {
                $res['address'] = self::cutAddress($ma[1]);
            }

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
        if (! preg_match_all('/ИНН\D{0,4}(\d{10,12})/iu', $flat, $m, PREG_OFFSET_CAPTURE)) {
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
     * Адрес обрывается там, где начинается следующая колонка документа:
     * «…ком. 12, Заказчик: тел.: …» — всё после подписи уже не адрес.
     */
    public static function cutAddress(string $raw): string
    {
        $cut = preg_split('/\s*(?:Заказчик|Покупатель|Поставщик|Исполнитель|Карта клиента|Ответственный|тел\.?:|e-?mail)/iu', trim($raw))[0] ?? $raw;

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

    private function linkEmail(Organization $org, string $email, array &$stats): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return;
        }
        $contact = ClientContact::firstOrCreate(['email' => $email]);

        // Закреплённый адрес не обогащаем: у посредника документы уходят на
        // конечных заказчиков, и одиннадцать таких PDF за год делали адрес
        // «многоюрлицным» — система начинала выбирать, чьи условия применить.
        if ($contact->pinned_organization_id !== null && (int) $contact->pinned_organization_id !== (int) $org->id) {
            $stats['pinned_skipped'] = ($stats['pinned_skipped'] ?? 0) + 1;

            return;
        }

        if (! $org->contacts()->where('client_contacts.id', $contact->id)->exists()) {
            $org->contacts()->attach($contact->id);
            $stats['links']++;
        }

        // Появилась связь email↔организация — точная привязка ещё не
        // привязанных заявок этого email к organization_id.
        $stats['requests_linked'] += $this->orgResolver->backfillForEmailLink($org, $email);
    }

    private function pdfText(int $attId): ?string
    {
        $att = EmailAttachment::find($attId);
        if (! $att || ! $att->file_path) {
            return null;
        }
        $disk = $att->disk ?: 'local';
        if (strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION)) !== 'pdf'
            || ! Storage::disk($disk)->exists($att->file_path)) {
            return null;
        }
        try {
            $text = (new Parser)
                ->parseFile(Storage::disk($disk)->path($att->file_path))
                ->getText();
        } catch (\Throwable $e) {
            return null;
        }

        return trim((string) $text) !== '' ? $text : null;
    }
}
