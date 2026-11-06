<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Поставщики — по кодам контрагентов 1С (переезд поставщиков в QuoteParts, решения заказчика 06.11.2026).
 *
 * Вход — JSON со списком поставщиков из выгрузки «Список контрагентов» 1С и их контактами
 * (OnecClientBaseImportService::counterparties/contacts, только kind=supplier):
 *  1. адреса из --drop убираются из реестра (не поставщики);
 *  2. каждый адрес получает организацию: поставщик — организация, адреса — её контакты;
 *  3. организации — код 1С по email из контактов 1С, затем по домену; один кандидат — код, несколько —
 *     кандидаты для выбора человеком; ручные коды — из --overrides (домен или email → код);
 *  4. остальные поставщики 1С (кроме «НЕ ИСПОЛЬЗОВАТЬ» и «ДУБЛЬ») заводятся организациями;
 *     все адреса из контактов 1С — контакты своих поставщиков (тема «Расценка»);
 *  5. ключ в QuoteParts — код 1С или временный «mz:{id}»; прежние «org:/sup:» переводятся
 *     в наших таблицах и в QuoteParts.
 * Без --apply — только отчёт.
 */
class SuppliersOnecBindCommand extends Command
{
    protected $signature = 'suppliers:onec-bind
        {file : JSON {suppliers:[{code,name,full,inn}], contacts:[{counterparty,emails[],name,phone}]}}
        {--overrides= : JSON {"домен или email": {"code":"00-…","name":"…"}} — коды, поставленные человеком}
        {--drop= : адреса через запятую, которые не поставщики — убрать из реестра}
        {--apply : выполнить}';

    protected $description = 'Поставщики по кодам 1С: организации, коды, контакты, постоянные ключи QuoteParts';

    private const SKIP_1C = '/не\s*использовать|дубль/iu';

    public function handle(QuotePartsClient $qp): int
    {
        $in = json_decode((string) file_get_contents($this->argument('file')), true);
        $overrides = $this->option('overrides') ? (array) json_decode((string) file_get_contents($this->option('overrides')), true) : [];
        $drop = array_values(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', (string) $this->option('drop')))));
        $apply = (bool) $this->option('apply');
        $free = (array) config('services.mail.free_mail_domains', []);
        $internal = array_map('mb_strtolower', (array) config('services.mail.internal_domains', ['myzip.ru']));

        // Индекс 1С.
        $byCode = collect($in['suppliers'])->keyBy('code');
        $codeByName = collect($in['suppliers'])->mapWithKeys(fn ($s) => [mb_strtolower(trim($s['name'])) => $s['code']]);
        $emailCodes = $domainCodes = $contactsByCode = [];
        foreach ($in['contacts'] as $c) {
            $code = $codeByName[mb_strtolower(trim($c['counterparty']))] ?? null;
            if ($code === null) {
                continue;
            }
            foreach ($c['emails'] as $e) {
                $e = mb_strtolower(trim($e));
                if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $d = Str::after($e, '@');
                if (in_array($d, $internal, true)) {
                    continue;
                }
                $emailCodes[$e][$code] = true;
                if (! in_array($d, $free, true)) {
                    $domainCodes[$d][$code] = true;
                }
                $contactsByCode[$code][$e] ??= ['name' => trim((string) ($c['name'] ?? '')) ?: null, 'phone' => $c['phone'] ?? null];
            }
        }

        $stats = ['убрано адресов' => 0, 'организаций создано для одиночных' => 0, 'код по email' => 0, 'код по домену' => 0, 'код вручную' => 0,
            'кандидаты' => 0, 'без кода' => 0, 'новых поставщиков из 1С' => 0, 'контактов из 1С' => 0, 'ключей переведено' => 0, 'ссылок QuoteParts переименовано' => 0];

        $run = function () use ($in, $overrides, $drop, $apply, $byCode, $emailCodes, $domainCodes, $contactsByCode, &$stats, $qp) {
            // 1. Не поставщики.
            if ($drop !== []) {
                $rows = Supplier::query()->whereIn(DB::raw('lower(email)'), $drop)->get();
                $stats['убрано адресов'] = $rows->count();
                foreach ($rows as $r) {
                    $this->line('  убрать: '.$r->email);
                    if ($apply) {
                        $r->delete();
                    }
                }
            }

            // Старые ключи — до любых изменений: организация «org:{id}», одиночный адрес «sup:{id}».
            $oldKeys = [];
            foreach (Supplier::query()->get(['id', 'supplier_organization_id']) as $s) {
                $oldKeys[] = ['old' => $s->supplier_organization_id ? 'org:'.$s->supplier_organization_id : 'sup:'.$s->id, 'supplier_id' => $s->id];
            }

            // 2. Одиночные адреса → организации.
            foreach (Supplier::query()->whereNull('supplier_organization_id')->get() as $s) {
                $stats['организаций создано для одиночных']++;
                if ($apply) {
                    $org = SupplierOrganization::create(['name' => $this->nameFor($s, $emailCodes, $byCode)]);
                    $s->forceFill(['supplier_organization_id' => $org->id])->save();
                }
            }

            // 3. Коды 1С существующим организациям.
            $usedCodes = SupplierOrganization::query()->whereNotNull('onec_code')->pluck('onec_code')->flip()->all();
            // Поставщики: организации; в отчёте (без --apply) одиночные адреса ещё без организаций — каждый сам по себе.
            $units = SupplierOrganization::query()->with('suppliers')->get()
                ->map(fn ($o) => ['org' => $o, 'name' => $o->name, 'suppliers' => $o->suppliers]);
            if (! $apply) {
                $units = $units->merge(Supplier::query()->whereNull('supplier_organization_id')->get()
                    ->map(fn ($s) => ['org' => null, 'name' => $s->name ?: $s->email, 'suppliers' => collect([$s])]));
            }
            foreach ($units as $unit) {
                $org = $unit['org'];
                if ($org?->onec_code) {
                    continue;
                }
                $emails = $unit['suppliers']->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->values();
                $domains = $unit['suppliers']->map(fn ($s) => mb_strtolower((string) ($s->domain ?: Str::after((string) $s->email, '@'))))->filter()->unique();
                $manual = collect($emails)->merge($domains)->map(fn ($k) => $overrides[$k] ?? null)->filter()->first();
                $codes = [];
                $how = null;
                if ($manual) {
                    $codes = [$manual['code'] => true];
                    $how = 'код вручную';
                }
                if (! $codes) {
                    foreach ($emails as $e) {
                        $codes += $emailCodes[$e] ?? [];
                    }
                    $how = $codes ? 'код по email' : null;
                }
                if (! $codes) {
                    foreach ($domains as $d) {
                        $codes += $domainCodes[$d] ?? [];
                    }
                    $how = $codes ? 'код по домену' : null;
                }
                $codes = array_keys($codes);
                $free = array_values(array_filter($codes, fn ($c) => ! isset($usedCodes[$c])));
                if (count($free) === 1) {
                    $stats[$how]++;
                    $usedCodes[$free[0]] = true;
                    $name = $manual['name'] ?? ($byCode[$free[0]]['name'] ?? null);
                    if ($apply) {
                        $org->forceFill(['onec_code' => $free[0], 'onec_name' => $name])->save();
                    }
                } elseif (count($codes) > 1) {
                    $stats['кандидаты']++;
                    $this->line('  кандидаты: '.$unit['name'].' → '.implode(', ', $codes));
                    if ($apply) {
                        $org->forceFill(['onec_candidates' => array_map(fn ($c) => ['code' => $c, 'name' => $byCode[$c]['name'] ?? $c], $codes)])->save();
                    }
                } else {
                    $stats['без кода']++;
                }
            }

            // 4. Остальные поставщики 1С — организациями, с контактами.
            foreach ($byCode as $code => $s1c) {
                if (isset($usedCodes[$code]) || preg_match(self::SKIP_1C, $s1c['name'].' '.$s1c['full']) === 1) {
                    continue;
                }
                $stats['новых поставщиков из 1С']++;
                if ($apply) {
                    SupplierOrganization::create(['name' => $this->clean($s1c['name']), 'inn' => $s1c['inn'] ?: null, 'onec_code' => $code, 'onec_name' => $s1c['name']]);
                }
                $usedCodes[$code] = true;
            }
            $known = Supplier::query()->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip()->all();
            foreach ($contactsByCode as $code => $emails) {
                $org = $apply ? SupplierOrganization::query()->where('onec_code', $code)->first() : null;
                foreach ($emails as $email => $info) {
                    if (isset($known[$email])) {
                        continue;
                    }
                    $known[$email] = true;
                    if (! $apply) {
                        $stats['контактов из 1С']++;

                        continue;
                    }
                    if ($org === null) {
                        continue;
                    }
                    $stats['контактов из 1С']++;
                    Supplier::create([
                        'email' => $email, 'domain' => Str::after($email, '@'), 'contact_person' => $info['name'],
                        'phone' => $info['phone'] ? mb_substr((string) $info['phone'], 0, 64) : null, 'language' => 'ru',
                        'supplier_organization_id' => $org->id,
                    ])->forceFill(['source' => 'onec', 'contact_topics' => ['quoting']])->save();
                }
            }

            if (! $apply) {
                return;
            }

            // 5. Постоянные ключи и перевод прежних.
            foreach (SupplierOrganization::query()->whereNull('qp_key')->get() as $org) {
                $org->forceFill(['qp_key' => $org->onec_code ?: SupplierOrganization::temporaryKey($org->id)])->save();
            }
            $map = [];
            foreach ($oldKeys as $row) {
                $new = Supplier::query()->with('organization')->find($row['supplier_id'])?->organization?->qp_key;
                if ($new !== null) {
                    $map[$row['old']] = $new;
                }
            }
            foreach ($map as $old => $new) {
                $n = DB::table('quoteparts_offers')->where('supplier_key', $old)->update(['supplier_key' => $new])
                    + DB::table('quoteparts_item_declines')->where('supplier_key', $old)->update(['supplier_key' => $new])
                    + DB::table('catalog_item_supplier_choices')->where('supplier_key', $old)->update(['supplier_key' => $new]);
                if (DB::table('supplier_onec_codes')->where('supplier_key', $new)->doesntExist()) {
                    $n += DB::table('supplier_onec_codes')->where('supplier_key', $old)->update(['supplier_key' => $new]);
                }
                $n += DB::update("update quoteparts_rfqs set snapshot = replace(snapshot::text, ?, ?)::jsonb where snapshot::text like ?",
                    ['"external_id": "'.$old.'"', '"external_id": "'.$new.'"', '%"external_id": "'.$old.'"%']);
                $stats['ключей переведено'] += $n;
            }

            // Ключи в QuoteParts — только тех, кто там уже заведён.
            foreach (collect($map)->unique() as $old => $new) {
                if (! Supplier::query()->whereNotNull('quoteparts_link_id')->whereHas('organization', fn ($q) => $q->where('qp_key', $new))->exists()) {
                    continue;
                }
                try {
                    $qp->updateSupplier($old, ['external_id' => $new]);
                    $stats['ссылок QuoteParts переименовано']++;
                } catch (Throwable $e) {
                    $this->warn('  QuoteParts '.$old.' → '.$new.': '.$e->getMessage());
                }
            }
        };

        $apply ? DB::transaction($run) : $run();

        $this->table(['что', 'сколько'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info($apply ? 'Готово.' : 'Это отчёт. Запусти с --apply, чтобы выполнить.');

        return self::SUCCESS;
    }

    /** Название новой организации для одиночного адреса: однозначный поставщик 1С, иначе имя/домен. */
    private function nameFor(Supplier $s, array $emailCodes, $byCode): string
    {
        $codes = array_keys($emailCodes[mb_strtolower((string) $s->email)] ?? []);
        if (count($codes) === 1 && isset($byCode[$codes[0]])) {
            return $this->clean($byCode[$codes[0]]['name']);
        }

        return trim((string) ($s->name ?: $s->domain ?: $s->email));
    }

    /** Название из 1С без приписок для своих: «(поставщик…)», «ЭДО». */
    private function clean(string $name): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $name));

        return mb_substr($n, 0, 255) ?: $name;
    }
}
