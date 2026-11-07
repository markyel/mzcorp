<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Ручное добавление поставщика в запрос цены (заявка → «Поставщики», «Снабжение»).
 *
 * Поставщик — организация (06.11.2026): в поиске одна строка на поставщика, по клику в запрос
 * встаёт его представитель (первый контакт «Расценка», см. SupplierMatchService::representatives),
 * и получателя выбирает QuoteParts. Конкретный адрес — дополнительная опция: адреса, совпавшие
 * с запросом по e-mail, показаны под поставщиком; выбранный так адрес уходит в QuoteParts явно.
 * Адрес без организации — сам себе поставщик.
 */
class SupplierManualSearch
{
    public const LIMIT = 8;

    /** Сколько совпавших адресов читаем, чтобы набрать LIMIT поставщиков. */
    private const SCAN = 200;

    /**
     * @param  list<int>  $excludeIds  адреса, уже стоящие в списке запроса
     * @return list<array{key:string, name:string, representative_id:int, email:string, addresses:int, org_id:?int, emails:list<array{id:int, email:string}>}>
     */
    public function search(string $query, array $excludeIds = []): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';

        // Название часто есть только у организации («BUENO» на двух адресах без своего имени).
        $matched = Supplier::query()
            ->with('organization:id,name')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('domain', 'ilike', $like)
                ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', $like)))
            ->orderBy('id')
            ->limit(self::SCAN)
            ->get(['id', 'name', 'email', 'supplier_organization_id', 'contact_topics', 'contact_position']);

        $orgIds = $matched->pluck('supplier_organization_id')->filter()->unique()->values();
        $orgAddresses = $orgIds->isEmpty() ? collect() : Supplier::query()
            ->whereIn('supplier_organization_id', $orgIds)->whereNotNull('email')
            ->get(['id', 'email', 'supplier_organization_id', 'contact_topics', 'contact_position']);

        return self::group($matched, $orgAddresses, $query, $excludeIds);
    }

    /**
     * Совпавшие адреса → поставщики. Без базы — чтобы проверять правило тестом.
     *
     * @param  Collection<int, Supplier>  $matched  адреса, совпавшие с запросом (с загруженной organization)
     * @param  Collection<int, Supplier>  $orgAddresses  все адреса организаций из $matched
     * @param  list<int>  $excludeIds
     * @return list<array{key:string, name:string, representative_id:int, email:string, addresses:int, org_id:?int, emails:list<array{id:int, email:string}>}>
     */
    public static function group(Collection $matched, Collection $orgAddresses, string $query, array $excludeIds = []): array
    {
        $exclude = array_map('intval', $excludeIds);
        $byOrg = $orgAddresses->groupBy(fn (Supplier $s) => (int) $s->supplier_organization_id);
        $needle = mb_strtolower(trim($query));
        $out = [];

        foreach ($matched->groupBy(fn (Supplier $s) => $s->supplier_organization_id ? 'o'.$s->supplier_organization_id : 's'.$s->id) as $key => $rows) {
            $first = $rows->first();
            $orgId = $first->supplier_organization_id ? (int) $first->supplier_organization_id : null;
            $all = $orgId !== null ? ($byOrg->get($orgId) ?? $rows) : $rows;

            // Поставщик уже в запросе (любым адресом) — остальные адреса добавляют кнопкой «все адреса».
            if ($all->contains(fn (Supplier $s) => in_array((int) $s->id, $exclude, true))) {
                continue;
            }
            $rep = SupplierMatchService::representatives($all)->first();
            if ($rep === null) {
                continue;
            }

            // Конкретный адрес предлагаем, только если человек искал по e-mail и это не представитель.
            $emails = $orgId === null ? [] : $rows
                ->filter(fn (Supplier $s) => (int) $s->id !== (int) $rep->id && $needle !== '' && str_contains(mb_strtolower((string) $s->email), $needle))
                ->sortBy('email')
                ->map(fn (Supplier $s) => ['id' => (int) $s->id, 'email' => (string) $s->email])
                ->values()->all();

            $out[] = [
                'key' => (string) $key,
                'name' => (string) (($first->relationLoaded('organization') ? $first->organization?->name : null) ?: $first->name ?: $first->email),
                'representative_id' => (int) $rep->id,
                'email' => (string) $rep->email,
                'addresses' => $all->count(),
                'org_id' => $orgId,
                'emails' => $emails,
            ];
        }

        usort($out, fn ($a, $b) => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return array_slice($out, 0, self::LIMIT);
    }
}
