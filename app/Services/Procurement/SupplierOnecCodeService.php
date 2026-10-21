<?php

namespace App\Services\Procurement;

use App\Models\OnecCounterparty;
use App\Models\Supplier;
use App\Models\SupplierOnecCode;
use App\Models\SupplierOrganization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Код поставщика в 1С для выгрузки цен: справочник контрагентов 1С УНФ (загружается файлом
 * «Список контрагентов») и привязка наших поставщиков (org:{id} / sup:{id}) к нему.
 *
 * Порядок: привязка, поставленная человеком → точное название из закупок 1С (так поставщик
 * записан в 1С) → точное название из нашего справочника поставщиков. Неоднозначное
 * («СИНТЭК» — два контрагента) сами не выбираем: подсказываем, решает снабжение.
 */
class SupplierOnecCodeService
{
    private const HEADERS = ['name' => 'наименование', 'code' => 'код', 'full_name' => 'полное наименование', 'inn' => 'инн', 'contract' => 'договор'];

    /**
     * Загрузить справочник из выгрузки 1С. Строки без кода пропускаются; контрагенты, которых
     * в файле нет, остаются (выгрузка могла быть частичной).
     *
     * @return array{rows:int, created:int, updated:int}
     */
    public function import(string $path): array
    {
        $rows = IOFactory::load($path)->getSheet(0)->toArray(null, true, true, false);
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0] ?? []);
        $col = [];
        foreach (self::HEADERS as $field => $title) {
            $i = array_search($title, $header, true);
            if ($i !== false) {
                $col[$field] = $i;
            }
        }
        if (! isset($col['name'], $col['code'])) {
            throw new RuntimeException('В файле нет колонок «Наименование» и «Код» — это не список контрагентов 1С.');
        }

        $get = fn (array $r, string $f) => isset($col[$f]) ? trim((string) ($r[$col[$f]] ?? '')) : '';
        $now = now();
        $batch = [];
        foreach (array_slice($rows, 1) as $r) {
            $code = $get($r, 'code');
            $name = $get($r, 'name');
            if ($code === '' || $name === '') {
                continue;
            }
            $batch[$code] = [
                'code' => mb_substr($code, 0, 32),
                'name' => mb_substr($name, 0, 500),
                'name_normalized' => mb_substr(OnecCounterparty::normalize($name), 0, 500),
                'full_name' => mb_substr($get($r, 'full_name'), 0, 500) ?: null,
                'inn' => mb_substr(preg_replace('/\D/', '', $get($r, 'inn')), 0, 20) ?: null,
                'contract' => mb_substr($get($r, 'contract'), 0, 255) ?: null,
                'seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $before = OnecCounterparty::query()->count();
        DB::transaction(function () use ($batch) {
            foreach (array_chunk(array_values($batch), 500) as $chunk) {
                OnecCounterparty::query()->upsert($chunk, ['code'], ['name', 'name_normalized', 'full_name', 'inn', 'contract', 'seen_at', 'updated_at']);
            }
        });
        $created = OnecCounterparty::query()->count() - $before;

        return ['rows' => count($batch), 'created' => $created, 'updated' => count($batch) - $created];
    }

    /**
     * Коды 1С поставщиков.
     *
     * @param  list<string>  $keys  org:{id} / sup:{id}
     * @param  array<string, string>  $names1c  key => название, как в 1С (по закупкам)
     * @return array<string, array{code:string, name:string, by:string}> только найденные
     */
    public function codesFor(array $keys, array $names1c = []): array
    {
        $out = [];
        $manual = SupplierOnecCode::query()->whereIn('supplier_key', $keys)->with('counterparty')->get()->keyBy('supplier_key');
        foreach ($keys as $key) {
            if ($m = $manual->get($key)) {
                $out[$key] = ['code' => $m->code, 'name' => (string) ($m->counterparty?->name ?? $m->code), 'by' => 'manual'];

                continue;
            }
            foreach (array_merge([$names1c[$key] ?? null], $this->registryNames($key)) as $name) {
                if ($name && ($cp = $this->uniqueByName($name)) !== null) {
                    $out[$key] = ['code' => $cp->code, 'name' => $cp->name, 'by' => 'name'];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Похожие контрагенты 1С — подсказки для привязки руками.
     *
     * @return Collection<int, OnecCounterparty>
     */
    public function suggestions(string $name, int $limit = 6): Collection
    {
        $norm = OnecCounterparty::normalize($name);
        if ($norm === '') {
            return collect();
        }
        // Самые длинные слова названия (без форм собственности) — по вхождению, потом по сходству.
        $words = collect(preg_split('/[\s,().\/]+/u', $norm))
            ->reject(fn ($w) => mb_strlen($w) < 3 || in_array($w, ['ооо', 'зао', 'оао', 'пао', 'ип', 'ltd', 'gmbh', 'co.', 'поставщик'], true))
            ->sortByDesc(fn ($w) => mb_strlen($w))->take(2)->values();

        return OnecCounterparty::query()
            ->where(function ($q) use ($words, $norm) {
                foreach ($words as $w) {
                    $q->orWhere('name_normalized', 'like', '%'.$w.'%');
                }
                $q->orWhere('name_normalized', $norm);
            })
            ->orderByRaw('CASE WHEN name_normalized = ? THEN 0 ELSE 1 END', [$norm])
            ->orderByRaw('length(name_normalized)')
            ->limit($limit)
            ->get();
    }

    /** Привязать поставщика к контрагенту 1С руками (пустой код — снять привязку). */
    public function setCode(string $supplierKey, ?string $code, ?User $by): void
    {
        $code = trim((string) $code);
        if ($code === '') {
            SupplierOnecCode::query()->where('supplier_key', $supplierKey)->delete();

            return;
        }
        if (! OnecCounterparty::query()->where('code', $code)->exists()) {
            throw new RuntimeException('Код '.$code.' не найден в справочнике контрагентов 1С.');
        }
        SupplierOnecCode::query()->updateOrCreate(['supplier_key' => $supplierKey], ['code' => $code, 'set_by_user_id' => $by?->id]);
    }

    /** Названия поставщика в нашем справочнике: организация и её адреса / сам поставщик. */
    public function registryNames(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');

        return array_values(array_unique(array_filter(match ($kind) {
            'org' => [SupplierOrganization::query()->whereKey((int) $id)->value('name'),
                ...Supplier::query()->where('supplier_organization_id', (int) $id)->pluck('name')->all()],
            'sup' => [Supplier::query()->whereKey((int) $id)->value('name')],
            default => [],
        })));
    }

    /** Код по названию (поставщик не из справочника, напр. адрес письма без привязки) — только однозначный. */
    public function codeForName(?string $name): ?string
    {
        return $name ? $this->uniqueByName($name)?->code : null;
    }

    private function uniqueByName(string $name): ?OnecCounterparty
    {
        $found = OnecCounterparty::query()->where('name_normalized', OnecCounterparty::normalize($name))->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }
}
