<?php

namespace App\Console\Commands;

use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
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

        $this->newLine();
        $this->table(['metric', 'value'], collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());
        $this->info('Готово. Дубли/мусор почистите вручную в разделе «Клиенты».');

        return self::SUCCESS;
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
        // Без ИНН и не из спец-поля компании — берём только если имя похоже на
        // юр.лицо (ООО/ИП/АО/кавычки), иначе это ФИО получателя, не организация.
        if (! $assumeOrg && $inn === '' && ! $this->looksLikeOrg($name)) {
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
    private function looksLikeOrg(string $name): bool
    {
        if (str_contains($name, '"') || str_contains($name, '«') || str_contains($name, '”')) {
            return true;
        }

        return preg_match('/(^|\W)(ООО|ОАО|ЗАО|АО|ПАО|ИП|НКО|ФГУП|МУП|ГУП|ГБУ|МБУ|АНО|ТСЖ|СНТ|КФХ|ООО|LLC|LTD|GMBH|INC)(\W|$)/iu', $name) === 1;
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

        $this->table(['кандидат', 'примерно'], [
            ['внешних email (контакты)', (string) $emails->count()],
            ['КП с реквизитами (организации)', (string) $quotesWithReq],
            ['уникальных client_company', (string) $companies],
        ]);
        $this->warn('Это DRY-RUN (оценка кандидатов). Запусти с --apply, чтобы заполнить реестр.');

        return self::SUCCESS;
    }
}
