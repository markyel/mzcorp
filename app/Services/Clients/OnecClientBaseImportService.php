<?php

namespace App\Services\Clients;

use App\Models\ClientContact;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

/**
 * Клиентская база из выгрузок 1С: «Список контрагентов» (иерархический), «Список контактов»
 * и журнал КП (номер заявки в комментарии → контрагент заявки).
 *
 * Кто клиент (решения заказчика 05.11.2026): контрагенты группы «Покупатели» — клиенты, группы
 * «Поставщики» — только поставщики. Вне групп: есть ИНН или полное наименование начинается с «ИП» —
 * клиент; наименование и полное наименование латиницей без ИНН — поставщик (99%); «поставщик»
 * в наименовании — поставщик; прочее — клиент. Группа строки — её уровень в выгрузке (outline).
 *
 * Организация — одна на ИНН (у одного ИНН в 1С бывает несколько контрагентов: договоры, ЭДО),
 * без ИНН — одна на наименование. Заполняются только пустые поля: повторный импорт ничего не
 * затирает. Контакты — только клиентов, ключ — email. Заявке организация ставится, только если
 * её ещё нет: по КП из журнала 1С, затем по адресу клиента, затем по домену (один клиент на домен).
 * Пишет напрямую, без OrganizationLinkGuard: это восстановление базы, а не новая привязка —
 * уведомлений менеджерам не будет.
 */
class OnecClientBaseImportService
{
    private const FOLDER_CLIENTS = 'Покупатели';

    private const FOLDER_SUPPLIERS = 'Поставщики';

    /**
     * @return list<array{name:string, code:string, full:string, group:string, city:string, inn:string, contract:string, kind:string, why:string}>
     */
    public function counterparties(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $sheet = $reader->load($path)->getActiveSheet();
        $head = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, false, false)[0]);
        $col = fn (string $title) => array_search($title, $head, true);
        foreach (['наименование', 'код', 'полное наименование', 'инн'] as $title) {
            if ($col($title) === false) {
                throw new RuntimeException("В списке контрагентов нет колонки «{$title}».");
            }
        }
        $letters = fn (int|false $i) => $i === false ? null : \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $c = array_map($letters, ['name' => $col('наименование'), 'code' => $col('код'), 'full' => $col('полное наименование'), 'group' => $col('группа компаний'),
            'city' => $col('город'), 'inn' => $col('инн'), 'contract' => $col('договор')]);
        $cell = fn (?string $letter, int $row) => $letter === null ? '' : trim((string) $sheet->getCell($letter.$row)->getValue());

        $out = [];
        $folder = null;
        for ($row = 2, $last = $sheet->getHighestRow(); $row <= $last; $row++) {
            $name = $cell($c['name'], $row);
            if ($name === '') {
                continue;
            }
            $level = $sheet->getRowDimension($row)->getOutlineLevel();
            if ($level === 0 && in_array($name, [self::FOLDER_CLIENTS, self::FOLDER_SUPPLIERS], true)) {
                $folder = $name;

                continue;
            }
            $x = ['name' => $name, 'code' => $cell($c['code'], $row), 'full' => $cell($c['full'], $row), 'group' => $cell($c['group'], $row),
                'city' => $cell($c['city'], $row), 'inn' => preg_replace('/\D/', '', $cell($c['inn'], $row)), 'contract' => $cell($c['contract'], $row)];
            [$x['kind'], $x['why']] = $level > 0 ? $this->byFolder($folder) : $this->outsideFolders($x);
            $out[] = $x;
        }

        return $out;
    }

    /** @return array{0:string, 1:string} */
    private function byFolder(?string $folder): array
    {
        return $folder === self::FOLDER_SUPPLIERS ? ['supplier', 'группа «Поставщики»'] : ['client', 'группа «Покупатели»'];
    }

    /** @return array{0:string, 1:string} */
    private function outsideFolders(array $x): array
    {
        $full = $x['full'] !== '' ? $x['full'] : $x['name'];
        $latin = '/^[\s"«(\'!.\d]*[A-Za-z]/u';

        return match (true) {
            mb_stripos($x['name'], 'поставщик') !== false => ['supplier', 'вне группы: «поставщик» в наименовании'],
            $x['inn'] !== '' => ['client', 'вне группы: есть ИНН'],
            preg_match('/^\s*ИП\b/u', $full) === 1 => ['client', 'вне группы: ИП'],
            preg_match($latin, $x['name']) === 1 && preg_match($latin, $full) === 1 => ['supplier', 'вне группы: латиница без ИНН'],
            default => ['client', 'вне группы: прочее'],
        };
    }

    /**
     * @return list<array{name:string, role:string, emails:list<string>, phone:?string, counterparty:string, responsible:string, date:?string}>
     */
    public function contacts(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);
        $head = array_map(fn ($v) => mb_strtolower(trim((string) $v)), array_shift($rows) ?? []);
        $all = fn (string $title) => array_keys($head, $title, true);
        $one = fn (string $title) => $all($title)[0] ?? null;
        if ($one('контрагент') === null || $all('email') === []) {
            throw new RuntimeException('В списке контактов нет колонок «Контрагент» и «Email».');
        }
        $phones = array_merge($all('телефон'), $all('рабочий'), $all('мобильный'));
        $get = fn (array $r, ?int $i) => $i === null ? '' : trim((string) ($r[$i] ?? ''));

        $out = [];
        foreach ($rows as $r) {
            $emails = [];
            foreach ($all('email') as $i) {
                foreach (preg_split('/[\s,;]+/', mb_strtolower($get($r, $i))) ?: [] as $e) {
                    if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = $e;
                    }
                }
            }
            $name = $get($r, $one('представление'));
            if ($name === '' && $emails === []) {
                continue;
            }
            $phone = null;
            foreach ($phones as $i) {
                if (($p = $get($r, $i)) !== '') {
                    $phone = $p;
                    break;
                }
            }
            $date = $r[$one('дата')] ?? null;
            $out[] = ['name' => $name, 'role' => $get($r, $one('роль')), 'emails' => array_values(array_unique($emails)), 'phone' => $phone,
                'counterparty' => $get($r, $one('контрагент')), 'responsible' => $get($r, $one('ответственный')),
                'date' => is_numeric($date) ? Date::excelToDateTimeObject((float) $date)->format('Y-m-d') : null];
        }

        return $out;
    }

    /**
     * Журнал КП 1С: номер заявки из комментария → контрагенты её КП.
     *
     * @param  list<string>  $paths
     * @return array<string, list<string>>
     */
    public function quoteClients(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $rows = $reader->load($path)->getActiveSheet()->toArray(null, false, false, false);
            $head = array_map(fn ($v) => mb_strtolower(trim((string) $v)), array_shift($rows) ?? []);
            $client = array_search('контрагент', $head, true);
            $comment = array_search('комментарий', $head, true);
            if ($client === false || $comment === false) {
                throw new RuntimeException("В журнале КП {$path} нет колонок «Контрагент» и «Комментарий».");
            }
            foreach ($rows as $r) {
                if (preg_match_all('/M-?\s?(20\d\d)-(\d{3,6})/u', (string) ($r[$comment] ?? ''), $m, PREG_SET_ORDER)) {
                    foreach ($m as $hit) {
                        $out[sprintf('M-%s-%s', $hit[1], $hit[2])][] = trim((string) $r[$client]);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Что будет записано: организации, контакты, связи, заявки. Ничего не пишет.
     *
     * @return array{organizations:array<string, array>, contacts:array<string, array>, requests:array<int, string>, stats:array<string, int>}
     */
    public function plan(array $counterparties, array $contacts, array $quoteClients, array $freeMailDomains): array
    {
        $stats = [];
        $inc = function (string $k, int $n = 1) use (&$stats) {
            $stats[$k] = ($stats[$k] ?? 0) + $n;
        };
        $orgKey = fn (array $x) => $x['inn'] !== '' ? 'inn:'.$x['inn'] : 'name:'.mb_strtolower($x['name']);

        // Переведённые из клиентов в поставщики (OrganizationToSupplierService): по ИНН, без ИНН — по названию.
        $supplierInns = DB::table('supplier_organizations')->whereNotNull('inn')->pluck('inn')->flip()->all();
        $supplierNames = DB::table('supplier_organizations')->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->flip()->all();

        $organizations = [];
        $byName = [];
        foreach ($counterparties as $x) {
            if ($x['kind'] === 'client' && ($x['inn'] !== '' ? isset($supplierInns[$x['inn']]) : isset($supplierNames[mb_strtolower($x['name'])]))) {
                $x['kind'] = 'supplier';
                $x['why'] = 'переведён в поставщики';
            }
            $inc('контрагентов: '.$x['why']);
            $byName[mb_strtolower($x['name'])] = $x;
            if ($x['kind'] !== 'client') {
                continue;
            }
            $key = $orgKey($x);
            $organizations[$key] ??= ['inn' => $x['inn'] ?: null, 'name' => $x['name'], 'full' => $x['full'], 'onec' => []];
            $organizations[$key]['onec'][] = array_filter(['name' => $x['name'], 'code' => $x['code'], 'contract' => $x['contract'], 'city' => $x['city'], 'group' => $x['group']]);
        }
        $inc('организаций', count($organizations));

        // Контакты клиентов: email → организации.
        $plannedContacts = [];
        foreach ($contacts as $c) {
            $x = $byName[mb_strtolower($c['counterparty'])] ?? null;
            if ($x === null) {
                $inc('контактов: контрагент не найден');

                continue;
            }
            if ($x['kind'] !== 'client') {
                $inc('контактов: поставщика (пропущены)');

                continue;
            }
            if ($c['emails'] === []) {
                $inc('контактов: без email (пропущены)');

                continue;
            }
            $inc('контактов клиентов');
            foreach ($c['emails'] as $email) {
                $plannedContacts[$email] ??= ['email' => $email, 'name' => $c['name'], 'phone' => $c['phone'], 'notes' => [], 'orgs' => []];
                $plannedContacts[$email]['orgs'][$orgKey($x)] = true;
                $plannedContacts[$email]['notes'][] = trim(implode(' · ', array_filter([$c['role'], '1С: '.$c['counterparty'], $c['responsible'] !== '' ? 'ответственный '.$c['responsible'] : null])));
                $plannedContacts[$email]['phone'] ??= $c['phone'];
            }
        }
        $inc('адресов контактов', count($plannedContacts));

        // Заявки без организации.
        $domainOrgs = [];
        foreach ($plannedContacts as $email => $p) {
            $domain = substr(strrchr($email, '@') ?: '', 1);
            if ($domain !== '' && ! in_array($domain, $freeMailDomains, true)) {
                foreach ($p['orgs'] as $key => $_) {
                    $domainOrgs[$domain][$key] = true;
                }
            }
        }
        $pending = DB::table('requests')->whereNull('organization_id')->get(['id', 'internal_code', 'client_email']);
        $byQuote = [];
        $learned = [];   // адрес → организации по заявкам, где организацию дал КП 1С
        foreach ($pending as $r) {
            foreach ($quoteClients[$r->internal_code] ?? [] as $name) {
                $x = $byName[mb_strtolower($name)] ?? null;
                if ($x !== null && $x['kind'] === 'client') {
                    $byQuote[$r->id] = $orgKey($x);
                    $email = mb_strtolower(trim((string) $r->client_email));
                    if ($email !== '') {
                        $learned[$email][$byQuote[$r->id]] = ($learned[$email][$byQuote[$r->id]] ?? 0) + 1;
                    }
                    break;
                }
            }
        }
        $requests = [];
        foreach ($pending as $r) {
            $key = $byQuote[$r->id] ?? null;
            if ($key !== null) {
                $inc('заявок: по КП из 1С');
            }
            $email = mb_strtolower(trim((string) $r->client_email));
            if ($key === null && isset($plannedContacts[$email]) && count($plannedContacts[$email]['orgs']) === 1) {
                $key = array_key_first($plannedContacts[$email]['orgs']);
                $inc('заявок: по адресу клиента');
            }
            // Адрес у нескольких организаций: та, на которую этот адрес чаще всего получал КП в 1С.
            if ($key === null && isset($learned[$email])) {
                arsort($learned[$email]);
                $key = array_key_first($learned[$email]);
                $inc('заявок: по адресу, организация из его КП');
            }
            $domain = substr(strrchr($email, '@') ?: '', 1);
            if ($key === null && count($domainOrgs[$domain] ?? []) === 1) {
                $key = array_key_first($domainOrgs[$domain]);
                $inc('заявок: по домену');
            }
            if ($key === null) {
                $inc('заявок: не определены');

                continue;
            }
            $requests[$r->id] = $key;
        }

        return ['organizations' => $organizations, 'contacts' => $plannedContacts, 'requests' => $requests, 'stats' => $stats];
    }

    /** @return array<string, int> */
    public function apply(array $plan): array
    {
        $counts = ['organizations_created' => 0, 'organizations_filled' => 0, 'contacts_created' => 0, 'links' => 0, 'requests' => 0];
        $orgIds = [];
        DB::transaction(function () use ($plan, &$counts, &$orgIds) {
            foreach ($plan['organizations'] as $key => $o) {
                $org = $o['inn'] !== null
                    ? Organization::query()->firstOrNew(['inn' => $o['inn']])
                    : Organization::query()->whereNull('inn')->whereRaw('lower(name) = ?', [mb_strtolower($o['name'])])->first() ?? new Organization;
                $created = ! $org->exists;
                $org->name = $org->name ?: $o['name'];
                $org->requisites_text = $org->requisites_text ?: ($o['full'] !== '' ? 'Полное наименование: '.$o['full'] : null);
                $org->notes = $org->notes ?: 'Из 1С: '.implode('; ', array_map(fn ($x) => implode(', ', $x), $o['onec']));
                if ($org->isDirty()) {
                    $org->save();
                    $counts[$created ? 'organizations_created' : 'organizations_filled']++;
                }
                $orgIds[$key] = $org->id;
            }

            $now = now();
            foreach ($plan['contacts'] as $email => $p) {
                $contact = ClientContact::query()->firstOrNew(['email' => $email]);
                if (! $contact->exists) {
                    $contact->fill(['full_name' => $p['name'] ?: null, 'phone' => $p['phone'], 'notes' => mb_substr(implode("\n", array_unique($p['notes'])), 0, 2000)])->save();
                    $counts['contacts_created']++;
                }
                $rows = [];
                foreach ($p['orgs'] as $key => $_) {
                    if (isset($orgIds[$key])) {
                        $rows[] = ['organization_id' => $orgIds[$key], 'client_contact_id' => $contact->id, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                $counts['links'] += DB::table('organization_contact')->insertOrIgnore($rows);
            }

            $byOrg = [];
            foreach ($plan['requests'] as $requestId => $key) {
                if (isset($orgIds[$key])) {
                    $byOrg[$orgIds[$key]][] = $requestId;
                }
            }
            foreach ($byOrg as $orgId => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    $counts['requests'] += DB::table('requests')->whereIn('id', $chunk)->whereNull('organization_id')->update(['organization_id' => $orgId]);
                }
            }
        });

        return $counts;
    }
}
