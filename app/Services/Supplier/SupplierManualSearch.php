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
    /** Сколько поставщиков видно сразу; остальные — по «ещё N — показать всех» (partials.supplier-manual-search-results). */
    public const LIMIT = 8;

    /** Сколько поставщиков отдаём всего: «показать всех» по «unisystem» — все «… через UniSystem» (18.11.2026). */
    public const MAX = 50;

    /** Сколько совпавших адресов читаем, чтобы набрать LIMIT поставщиков. */
    private const SCAN = 200;

    /**
     * @param  list<int>  $excludeIds  адреса, уже стоящие в списке запроса (in_request в ответе)
     * @return list<array{key:string, name:string, representative_id:int, email:string, addresses:int, org_id:?int, in_request:bool, emails:list<array{id:int, email:string, in_request:bool}>}>
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
            ->notArchived()
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
     * @return list<array{key:string, name:string, representative_id:int, email:string, addresses:int, org_id:?int, in_request:bool, emails:list<array{id:int, email:string, in_request:bool}>}>
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

            // Поставщик уже в запросе (любым адресом) — всё равно показываем, с пометкой: раньше он
            // пропадал из поиска, и искавший конкретный адрес (nes@is-com.ru, заявка 19476, 09.11.2026)
            // не находил его, потому что в списке уже стоял gao@ той же организации.
            $inRequest = $all->contains(fn (Supplier $s) => in_array((int) $s->id, $exclude, true));
            $rep = SupplierMatchService::representatives($all)->first();
            if ($rep === null) {
                continue;
            }

            // Конкретный адрес предлагаем, если человек искал по e-mail. Представителя не повторяем —
            // его добавляет строка поставщика; но если поставщик уже в запросе, строка его только
            // отмечает, и нужный адрес показываем отдельно, даже если это представитель.
            // Запрос подошёл к нескольким адресам (домен «unisystem.si») — только адреса для расценок (роль «Расценка»):
            // предлагался и alex@ — у него «Логистика» (18.11.2026). Запрос указывает на один адрес («logistics@»,
            // «alex@unisystem.si») — его предлагаем любым. Роли у организации не отмечены — все.
            $quoting = fn (Supplier $s) => in_array('quoting', (array) ($s->contact_topics ?: []), true);
            $hit = fn (Supplier $s) => $needle !== '' && str_contains(mb_strtolower((string) $s->email), $needle);
            $broad = $rows->filter($hit)->count() > 1 && $all->contains($quoting);
            $emails = $orgId === null ? [] : $rows
                ->filter(fn (Supplier $s) => ($inRequest || (int) $s->id !== (int) $rep->id) && $hit($s) && (! $broad || $quoting($s)))
                ->sortBy('email')
                ->map(fn (Supplier $s) => ['id' => (int) $s->id, 'email' => (string) $s->email, 'in_request' => in_array((int) $s->id, $exclude, true)])
                ->values()->all();

            $out[] = [
                'key' => (string) $key,
                'name' => (string) (($first->relationLoaded('organization') ? $first->organization?->name : null) ?: $first->name ?: $first->email),
                'representative_id' => (int) $rep->id,
                'email' => (string) $rep->email,
                'addresses' => $all->count(),
                'org_id' => $orgId,
                'in_request' => $inRequest,
                'emails' => $emails,
                // Все адреса организации с ролями — «адреса ▾» в строке: выбрать любой ящик, даже если нашли по названию
                // (18.11.2026: по «system» раскрывались только UniSystem и SEA SYSTEMS — у них «system» в адресе).
                'all_emails' => $orgId === null ? [] : $all
                    ->sortBy(fn (Supplier $s) => [$s->contact_position ?? 999, $s->id])
                    ->map(fn (Supplier $s) => ['id' => (int) $s->id, 'email' => (string) $s->email,
                        'topics' => array_values(array_intersect_key(\App\Livewire\Suppliers\SupplierCard::TOPICS, array_flip((array) ($s->contact_topics ?: [])))),
                        'in_request' => in_array((int) $s->id, $exclude, true)])
                    ->values()->all(),
            ];
        }

        // Сначала тот, кого и искали: название совпало или начинается с запроса, адрес на домене из запроса; потом по
        // алфавиту. «unisystem» находил 9 «… через UniSystem», и сама UniSystem по алфавиту не входила в LIMIT (18.11.2026).
        $rank = function (array $r) use ($needle, $matched): int {
            $name = mb_strtolower(trim($r['name']));
            $domainHit = $matched->contains(fn (Supplier $s) => (string) $s->supplier_organization_id === (string) $r['org_id'] || ($r['org_id'] === null && (int) $s->id === $r['representative_id'])
                ? str_starts_with((string) substr((string) strrchr(mb_strtolower((string) $s->email), '@'), 1), $needle)
                : false);

            return match (true) {
                $name === $needle => 0,
                str_starts_with($name, $needle) || $domainHit => 1,
                default => 2,
            };
        };
        usort($out, fn ($a, $b) => [$rank($a), mb_strtolower($a['name'])] <=> [$rank($b), mb_strtolower($b['name'])]);

        return array_slice($out, 0, self::MAX);
    }
}
