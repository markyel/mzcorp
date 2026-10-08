<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Организации поставщиков: объединение нескольких адресов реестра одной
 * компании. Запись реестра остаётся единицей рассылки — письмо уходит на
 * адрес, организация только группирует адреса в списках.
 */
class SupplierOrganizationService
{
    /**
     * Объединить адреса в одну организацию.
     *
     * Если среди выбранных уже есть организации — берём самую крупную и
     * вливаем в неё остальные целиком (их адреса переезжают, пустые
     * организации удаляются). Иначе создаём новую.
     *
     * @param  array<int, int>  $supplierIds
     */
    public function merge(array $supplierIds, ?string $name = null, ?int $userId = null): ?SupplierOrganization
    {
        $suppliers = Supplier::query()->whereIn('id', array_map('intval', $supplierIds))->get();
        if ($suppliers->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($suppliers, $name, $userId) {
            $orgIds = $suppliers->pluck('supplier_organization_id')->filter()->unique()->values();
            $target = $orgIds->isEmpty()
                ? null
                : SupplierOrganization::query()->whereIn('id', $orgIds)->withCount('suppliers')
                    ->orderByDesc('suppliers_count')->orderBy('id')->first();

            $name = trim((string) $name);
            if ($target === null) {
                $target = SupplierOrganization::create([
                    'name' => mb_substr($name !== '' ? $name : $this->suggestName($suppliers), 0, 255),
                    'created_by_user_id' => $userId,
                ]);
            } elseif ($name !== '' && $name !== $target->name) {
                $target->update(['name' => mb_substr($name, 0, 255)]);
            }

            $absorbed = $orgIds->reject(fn ($id) => (int) $id === (int) $target->id)->all();
            if ($absorbed !== []) {
                Supplier::query()->whereIn('supplier_organization_id', $absorbed)
                    ->update(['supplier_organization_id' => $target->id]);
                SupplierOrganization::query()->whereIn('id', $absorbed)->delete();
            }
            Supplier::query()->whereIn('id', $suppliers->pluck('id'))
                ->update(['supplier_organization_id' => $target->id]);

            return $target->fresh();
        });
    }

    /** Перевести адрес в существующую организацию; прежняя, опустев, удаляется. */
    public function attach(Supplier $supplier, int $organizationId): bool
    {
        if (! SupplierOrganization::query()->whereKey($organizationId)->exists()) {
            return false;
        }
        $previous = $supplier->supplier_organization_id;
        $supplier->update(['supplier_organization_id' => $organizationId]);
        if ($previous !== null && (int) $previous !== $organizationId) {
            $this->dropIfEmpty((int) $previous);
        }

        return true;
    }

    /** Вывести адрес из организации; опустевшая организация удаляется. */
    public function detach(Supplier $supplier): void
    {
        $orgId = $supplier->supplier_organization_id;
        if ($orgId === null) {
            return;
        }
        $supplier->update(['supplier_organization_id' => null]);
        $this->dropIfEmpty((int) $orgId);
    }

    public function rename(int $organizationId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        return SupplierOrganization::query()->whereKey($organizationId)
            ->update(['name' => mb_substr($name, 0, 255)]) > 0;
    }

    public function dropIfEmpty(int $organizationId): void
    {
        if (! Supplier::query()->where('supplier_organization_id', $organizationId)->exists()) {
            SupplierOrganization::query()->whereKey($organizationId)->delete();
        }
    }

    /**
     * Название новой организации: самое частое непустое название адресов
     * (при равенстве — самое длинное), иначе корпоративный домен.
     *
     * @param  Collection<int, Supplier>  $suppliers
     */
    public function suggestName(Collection $suppliers): string
    {
        $names = $suppliers->map(fn (Supplier $s) => trim((string) $s->name))
            ->filter(fn ($n) => $n !== '' && ! str_contains($n, '@'));
        $counts = $names->countBy()->sortByDesc(fn ($count, $n) => $count * 1000 + mb_strlen((string) $n));
        // Разные названия по одному разу — это обычно имена сотрудников
        // («Сабиров Максим», «Шкляев Дмитрий»), а не компания: meteor.ru
        // так стал «Долотова Любовь». Тогда честнее домен.
        if ($counts->isNotEmpty() && ($counts->count() === 1 || $counts->first() > 1)) {
            return (string) $counts->keys()->first();
        }

        $domain = $suppliers->map(fn (Supplier $s) => self::domainOf($s))->filter()->first();

        return (string) ($domain ?: ($suppliers->first()->email ?: 'Организация'));
    }

    /**
     * Подсказки объединения: адреса на одном корпоративном домене, которые ещё
     * не в одной организации. Публичные почтовики пропускаем — общий домен там
     * ничего не значит.
     *
     * @return list<array{domain: string, name: string, suppliers: Collection<int, Supplier>}>
     */
    public function domainSuggestions(): array
    {
        $free = array_map('mb_strtolower', (array) config('services.mail.free_mail_domains', []));

        return Supplier::query()->get(['id', 'email', 'domain', 'name', 'supplier_organization_id'])
            ->groupBy(fn (Supplier $s) => (string) self::domainOf($s))
            ->filter(fn (Collection $g, $domain) => $domain !== ''
                && ! in_array($domain, $free, true)
                && $g->count() > 1
                && ($g->pluck('supplier_organization_id')->unique()->count() > 1
                    || $g->whereNull('supplier_organization_id')->isNotEmpty()))
            ->sortByDesc(fn (Collection $g) => $g->count())
            ->map(fn (Collection $g, $domain) => [
                'domain' => (string) $domain,
                'name' => $this->suggestName($g),
                'suppliers' => $g->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * Список выбора поставщиков для запроса цены: адреса одной организации
     * подряд, организация стоит там, где её лучший по покрытию адрес.
     * Добавляет org_id / org_name / org_size (сколько её адресов в списке).
     *
     * @param  list<array<string, mixed>>  $options  строки с id и item_count
     * @return list<array<string, mixed>>
     */
    public static function withOrganizations(array $options): array
    {
        if ($options === []) {
            return [];
        }

        $bound = Supplier::query()->whereIn('id', array_column($options, 'id'))
            ->whereNotNull('supplier_organization_id')
            ->with('organization:id,name')
            ->get(['id', 'supplier_organization_id'])->keyBy('id');

        $first = [];
        $score = [];
        $size = [];
        foreach ($options as $i => $o) {
            $s = $bound->get($o['id']);
            $orgId = $s?->organization ? (int) $s->supplier_organization_id : null;
            $key = $orgId !== null ? 'o'.$orgId : 's'.$o['id'];
            $options[$i] += ['org_id' => $orgId, 'org_name' => $orgId !== null ? (string) $s->organization->name : null];
            $options[$i]['_k'] = $key;
            $options[$i]['_i'] = $i;
            $first[$key] ??= $i;
            $score[$key] = max($score[$key] ?? 0, (int) ($o['item_count'] ?? 0));
            $size[$key] = ($size[$key] ?? 0) + 1;
        }

        usort($options, fn ($a, $b) => [$score[$b['_k']], $first[$a['_k']], (int) ($b['item_count'] ?? 0), $a['_i']]
            <=> [$score[$a['_k']], $first[$b['_k']], (int) ($a['item_count'] ?? 0), $b['_i']]);

        return array_map(function (array $o) use ($size) {
            $o['org_size'] = $o['org_id'] !== null ? $size[$o['_k']] : 0;
            unset($o['_k'], $o['_i']);

            return $o;
        }, $options);
    }

    /**
     * По одному адресу на организацию — для выбора группы поставщиков целиком
     * («Китай запросы»): письмо одной компании на два-три её адреса — дубль
     * для поставщика. Адреса без организации проходят все.
     *
     * Кого оставить от организации, по порядку:
     *   1) адрес из $prefer (уже отмечен или подходит под позиции) — в его порядке;
     *   2) кто чаще присылал цены на наши запросы;
     *   3) самый старый адрес реестра.
     *
     * @param  list<int>  $supplierIds
     * @param  list<int>  $prefer
     * @return list<int>
     */
    public static function onePerOrganization(array $supplierIds, array $prefer = []): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $suppliers = Supplier::query()->whereIn('id', $supplierIds)
            ->get(['id', 'email', 'supplier_organization_id'])->keyBy('id');

        $byOrg = [];
        $out = [];
        foreach ($supplierIds as $id) {
            $s = $suppliers->get($id);
            if ($s === null) {
                continue;
            }
            if ($s->supplier_organization_id === null) {
                $out[] = (int) $id;
            } else {
                $byOrg[(int) $s->supplier_organization_id][] = $s;
            }
        }
        if ($byOrg === []) {
            return $out;
        }

        $quoted = self::quotedCountsByEmail(
            collect($byOrg)->flatten()->filter(fn ($s) => count($byOrg[(int) $s->supplier_organization_id]) > 1)
                ->pluck('email')->all()
        );
        $preferRank = array_flip(array_map('intval', $prefer));

        foreach ($byOrg as $list) {
            usort($list, function (Supplier $a, Supplier $b) use ($preferRank, $quoted) {
                $ra = $preferRank[$a->id] ?? PHP_INT_MAX;
                $rb = $preferRank[$b->id] ?? PHP_INT_MAX;
                $qa = $quoted[mb_strtolower((string) $a->email)] ?? 0;
                $qb = $quoted[mb_strtolower((string) $b->email)] ?? 0;

                return [$ra, $qb, $a->id] <=> [$rb, $qa, $b->id];
            });
            $out[] = (int) $list[0]->id;
        }

        return $out;
    }

    /**
     * Группа «выбрана», если от каждой её организации (и каждого адреса без
     * организации) отмечен хотя бы один адрес.
     *
     * @param  iterable<Supplier>  $suppliers  адреса группы (с supplier_organization_id)
     * @param  array<int, bool>  $selected  id адреса => отмечен
     */
    public static function everyOrganizationSelected(iterable $suppliers, array $selected): bool
    {
        $buckets = [];
        foreach ($suppliers as $s) {
            $key = $s->supplier_organization_id !== null ? 'o'.$s->supplier_organization_id : 's'.$s->id;
            $buckets[$key] = ($buckets[$key] ?? false) || ! empty($selected[(int) $s->id]);
        }

        return $buckets !== [] && ! in_array(false, $buckets, true);
    }

    /**
     * Сколько раз адрес присылал цену на наш запрос (supplier_offers.outcome = quoted).
     *
     * @param  list<?string>  $emails
     * @return array<string, int> lower(email) => count
     */
    private static function quotedCountsByEmail(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)), $emails
        ))));
        if ($emails === []) {
            return [];
        }

        return DB::table('supplier_offers as o')
            ->join('supplier_inquiries as i', 'i.id', '=', 'o.supplier_inquiry_id')
            ->where('o.outcome', 'quoted')
            ->whereIn(DB::raw('lower(i.supplier_email)'), $emails)
            ->groupBy(DB::raw('lower(i.supplier_email)'))
            ->selectRaw('lower(i.supplier_email) as email, count(*) as c')
            ->pluck('c', 'email')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    public static function domainOf(Supplier $supplier): ?string
    {
        $domain = mb_strtolower(trim((string) $supplier->domain));
        if ($domain === '' && str_contains((string) $supplier->email, '@')) {
            $domain = mb_strtolower(substr((string) strrchr((string) $supplier->email, '@'), 1));
        }

        return $domain !== '' ? $domain : null;
    }
}
