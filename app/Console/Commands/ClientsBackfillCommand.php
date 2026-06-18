<?php

namespace App\Console\Commands;

use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
use App\Services\Clients\RequestOrganizationResolver;
use Illuminate\Console\Command;

/**
 * Бэкфилл реестра «Клиенты» из накопленных данных:
 *  - контакты — из внешних requests.client_email (+ ФИО/телефон);
 *  - организации — из реквизитов отправленных КП (recipient_inn/name/address/
 *    card_text/discount) и из requests.client_company;
 *  - связи email↔организация — по факту использования (КП/заявка связывает
 *    конкретный email с конкретной организацией).
 *
 * Идемпотентно (firstOrNew по inn|name / email; повторный прогон не плодит
 * дубли по тем же ключам). Часть мусора в данных неизбежна — чистим вручную
 * в разделе «Клиенты».
 *
 *   php artisan clients:backfill            # оценка кандидатов (dry-run)
 *   php artisan clients:backfill --apply    # реально заполнить
 */
class ClientsBackfillCommand extends Command
{
    protected $signature = 'clients:backfill {--apply : Реально писать в реестр (без флага — оценка)}';

    protected $description = 'Заполнить реестр «Клиенты» (организации + контакты + связи) из заявок и КП';

    /** @var array<int, string> */
    private array $internalDomains = [];

    public function __construct(private readonly RequestOrganizationResolver $orgResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->internalDomains = array_values(array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('services.mail.internal_domains', ['myzip.ru']),
        )));

        if (! $apply) {
            return $this->dryRun();
        }

        $stats = ['contacts' => 0, 'orgs' => 0, 'links' => 0];

        // 1) Контакты из заявок (внешние email). Идём от свежих к старым, чтобы
        //    при создании контакта взять самое свежее ФИО/телефон.
        RequestModel::query()
            ->whereNotNull('client_email')->where('client_email', '!=', '')
            ->orderByDesc('id')
            ->chunkById(500, function ($chunk) use (&$stats) {
                foreach ($chunk as $r) {
                    $email = mb_strtolower(trim((string) $r->client_email));
                    if ($email === '' || $this->isInternal($email)) {
                        continue;
                    }
                    $c = ClientContact::firstOrNew(['email' => $email]);
                    $isNew = ! $c->exists;
                    if (trim((string) ($c->full_name ?? '')) === '' && trim((string) $r->client_name) !== '') {
                        $c->full_name = trim((string) $r->client_name);
                    }
                    if (trim((string) ($c->phone ?? '')) === '' && trim((string) $r->client_phone) !== '') {
                        $c->phone = trim((string) $r->client_phone);
                    }
                    $c->save();
                    if ($isNew) {
                        $stats['contacts']++;
                    }
                }
            });

        // 2) Организации из реквизитов отправленных КП.
        Quotation::query()
            ->where(function ($q) {
                $q->where(fn ($w) => $w->whereNotNull('recipient_inn')->where('recipient_inn', '!=', ''))
                    ->orWhere(fn ($w) => $w->whereNotNull('recipient_name')->where('recipient_name', '!=', ''));
            })
            ->with('request:id,client_email')
            ->orderBy('id')
            ->chunkById(300, function ($chunk) use (&$stats) {
                foreach ($chunk as $q) {
                    // recipient_name в КП часто = ФИО получателя, а не юр.лицо.
                    // Организацию создаём только если есть ИНН или имя похоже на
                    // юр.лицо; ФИО-получатели остаются только контактами.
                    $org = $this->resolveOrg($q->recipient_name, $q->recipient_inn, $stats);
                    if (! $org) {
                        continue;
                    }
                    if (trim((string) ($org->address ?? '')) === '' && trim((string) $q->recipient_address) !== '') {
                        $org->address = trim((string) $q->recipient_address);
                    }
                    if (trim((string) ($org->requisites_text ?? '')) === '' && trim((string) $q->recipient_card_text) !== '') {
                        $org->requisites_text = trim((string) $q->recipient_card_text);
                    }
                    if ((float) $org->discount_percent === 0.0 && (float) $q->discount_percent > 0) {
                        $org->discount_percent = (float) $q->discount_percent;
                    }
                    $org->save();
                    $this->linkEmail($org, (string) ($q->request?->client_email ?? ''), $stats);
                }
            });

        // 3) Организации из client_company заявок (для заявок без КП-реквизитов).
        RequestModel::query()
            ->whereNotNull('client_company')->where('client_company', '!=', '')
            ->whereNotNull('client_email')->where('client_email', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$stats) {
                foreach ($chunk as $r) {
                    // client_company — спец-поле компании из веб-формы, считаем
                    // организацией всегда (assumeOrg), даже без орг-маркера.
                    $org = $this->resolveOrg($r->client_company, null, $stats, assumeOrg: true);
                    if (! $org) {
                        continue;
                    }
                    $org->save();
                    $this->linkEmail($org, (string) $r->client_email, $stats);
                }
            });

        // 4) Точная привязка заявок к организациям (requests.organization_id).
        //    Делаем ПОСЛЕ создания связей email↔организация (шаги 2-3), чтобы
        //    резолвер видел готовый граф. Консервативно: только однозначные
        //    кандидаты (email ровно с одной организацией / совпадение
        //    client_company), неоднозначные остаются null.
        $stats['requests_linked'] = $this->linkRequestsToOrgs();

        $this->newLine();
        $this->table(['metric', 'value'], collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());
        $this->info('Готово. Дубли/мусор почистите вручную в разделе «Клиенты».');

        return self::SUCCESS;
    }

    /**
     * Привязать заявки к организациям через резолвер. Возвращает число заявок,
     * получивших organization_id за этот прогон.
     */
    private function linkRequestsToOrgs(): int
    {
        $linked = 0;

        // 4a) email ровно с одной организацией → set-based привязка ещё не
        //     привязанных заявок этого email (один UPDATE на контакт).
        ClientContact::query()
            ->has('organizations', '=', 1)
            ->with('organizations:id')
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$linked) {
                foreach ($chunk as $c) {
                    $org = $c->organizations->first();
                    if ($org !== null) {
                        $linked += $this->orgResolver->backfillForEmailLink($org, (string) $c->email);
                    }
                }
            });

        // 4b) оставшиеся без привязки — по client_company (веб-форма).
        RequestModel::query()
            ->whereNull('organization_id')
            ->whereNotNull('client_company')->where('client_company', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$linked) {
                foreach ($chunk as $r) {
                    if ($this->orgResolver->attach($r)) {
                        $linked++;
                    }
                }
            });

        return $linked;
    }

    /**
     * firstOrNew организации по ИНН (если есть) либо по имени; гарантирует
     * непустое name. Считает новые в $stats['orgs'].
     */
    private function resolveOrg(?string $name, ?string $inn, array &$stats, bool $assumeOrg = false): ?Organization
    {
        $inn = preg_replace('/\D+/', '', (string) $inn) ?? '';
        $name = trim((string) $name);
        if ($inn === '' && $name === '') {
            return null;
        }
        // Без ИНН и не из спец-поля компании — считаем организацией всё, что НЕ
        // похоже на ФИО получателя (явные «Фамилия Имя [Отчество]»). Так ловим и
        // безмаркерные компании («СтайлЛифт», «СП Интерлифт»), и отсекаем людей.
        if (! $assumeOrg && $inn === '' && $this->looksLikePerson($name)) {
            return null;
        }

        $org = $inn !== ''
            ? Organization::firstOrNew(['inn' => $inn])
            : Organization::firstOrNew(['name' => $name]);

        if (! $org->exists) {
            $stats['orgs']++;
        }
        if (trim((string) ($org->name ?? '')) === '') {
            $org->name = $name !== '' ? $name : ('ИНН ' . $inn);
        }

        return $org;
    }

    /**
     * Имя похоже на юридическое лицо (орг-форма или кавычки в названии).
     * Отсекает ФИО-получателей КП от настоящих организаций.
     */
    /**
     * Имя похоже на ФИО физлица: «Фамилия Имя» / «Фамилия Имя Отчество» (2–3
     * слова с заглавной + строчные, кириллица или латиница). Орг-маркер
     * (ООО/ИП/кавычки) — точно НЕ ФИО. Всё прочее (включая безмаркерные
     * компании «СтайлЛифт», «СП Интерлифт») ФИО не считаем → это организация.
     */
    private function looksLikePerson(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if (str_contains($name, '"') || str_contains($name, '«') || str_contains($name, '”')
            || preg_match('/(^|\W)(ООО|ОАО|ЗАО|АО|ПАО|ИП|НКО|ФГУП|МУП|ГУП|ГБУ|МБУ|АНО|ТСЖ|СНТ|КФХ|LLC|LTD|GMBH|INC)(\W|$)/iu', $name) === 1) {
            return false;
        }
        // Кириллическое ФИО.
        if (preg_match('/^[А-ЯЁ][а-яё]+(-[А-ЯЁ][а-яё]+)?(\s+[А-ЯЁ][а-яё.]+){1,2}$/u', $name) === 1) {
            return true;
        }
        // Латинское «First Last [Middle]».
        if (preg_match('/^[A-Z][a-z]+(\s+[A-Z][a-z.]+){1,2}$/u', $name) === 1) {
            return true;
        }

        return false;
    }

    private function linkEmail(Organization $org, string $email, array &$stats): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $this->isInternal($email)) {
            return;
        }
        $contact = ClientContact::firstOrCreate(['email' => $email]);
        if (! $org->contacts()->where('client_contacts.id', $contact->id)->exists()) {
            $org->contacts()->attach($contact->id);
            $stats['links']++;
        }
    }

    private function isInternal(string $email): bool
    {
        foreach ($this->internalDomains as $d) {
            if ($d !== '' && str_ends_with($email, '@' . $d)) {
                return true;
            }
        }

        return false;
    }

    private function dryRun(): int
    {
        $emails = RequestModel::query()
            ->whereNotNull('client_email')->where('client_email', '!=', '')
            ->distinct()->pluck('client_email')
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->reject(fn ($e) => $e === '' || $this->isInternal($e))
            ->unique();

        $quotesWithReq = Quotation::query()
            ->where(function ($q) {
                $q->where(fn ($w) => $w->whereNotNull('recipient_inn')->where('recipient_inn', '!=', ''))
                    ->orWhere(fn ($w) => $w->whereNotNull('recipient_name')->where('recipient_name', '!=', ''));
            })->count();

        $companies = RequestModel::query()
            ->whereNotNull('client_company')->where('client_company', '!=', '')
            ->distinct()->count('client_company');

        $unlinkedRequests = RequestModel::query()->whereNull('organization_id')->count();

        $this->table(['кандидат', 'примерно'], [
            ['внешних email (контакты)', (string) $emails->count()],
            ['КП с реквизитами (организации)', (string) $quotesWithReq],
            ['уникальных client_company', (string) $companies],
            ['заявок без organization_id', (string) $unlinkedRequests],
        ]);
        $this->warn('Это DRY-RUN (оценка кандидатов). Запусти с --apply, чтобы заполнить реестр.');

        return self::SUCCESS;
    }
}
