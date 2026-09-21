<?php

namespace App\Services\Clients;

use App\Models\ClientDiscount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Загрузка скидок контрагентов из выгрузки корпоративной базы.
 *
 * Формат листа: Контрагент | ИНН | ГруппаКомпаний | СкидкаПроцент. Заголовок
 * ищем по названиям колонок, а не по номерам: порядок в выгрузке однажды
 * поменяется, и молча загруженные «скидки» из колонки ИНН — худшее, что может
 * случиться с ценами.
 *
 * Скидка проставляется в карточку организации по ИНН. Контрагенты, которых у
 * нас ещё нет, всё равно сохраняются: организация появится — скидка применится
 * следующим импортом или при сверке.
 */
class ClientDiscountImportService
{
    /** Скидка выше этой — почти наверняка ошибка выгрузки, а не подарок клиенту. */
    public const MAX_SANE_DISCOUNT = 60.0;

    /**
     * Разобрать файл, ничего не сохраняя.
     *
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, stats: array<string, int>}
     */
    public function parse(string $path, ?string $originalName = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);
        $table = $sheet->toArray(null, true, false, false);

        $map = $this->headerMap($table[0] ?? []);
        $errors = [];
        foreach (['name', 'inn', 'discount'] as $required) {
            if (! isset($map[$required])) {
                $errors[] = 'В файле не найдена колонка: '.$required;
            }
        }
        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors, 'stats' => []];
        }

        $rows = [];
        $stats = ['total' => 0, 'ok' => 0, 'no_inn' => 0, 'bad_discount' => 0, 'padded_inn' => 0];

        foreach (array_slice($table, 1) as $i => $raw) {
            $name = trim((string) ($raw[$map['name']] ?? ''));
            $innRaw = trim((string) ($raw[$map['inn']] ?? ''));
            if ($name === '' && $innRaw === '') {
                continue;
            }
            $stats['total']++;

            $inn = ClientDiscount::normalizeInn($innRaw);
            $discount = (float) str_replace(',', '.', (string) ($raw[$map['discount']] ?? ''));

            if ($inn === null) {
                $stats['no_inn']++;
                $errors[] = 'строка '.($i + 2).': без ИНН — '.($name ?: '(без названия)');

                continue;
            }
            if ($inn !== preg_replace('/\D+/', '', $innRaw)) {
                $stats['padded_inn']++;
            }
            if ($discount < 0 || $discount > self::MAX_SANE_DISCOUNT) {
                $stats['bad_discount']++;
                $errors[] = 'строка '.($i + 2).': скидка '.$discount.'% — пропущена как неправдоподобная';

                continue;
            }

            $stats['ok']++;
            $rows[] = [
                'inn' => $inn,
                'name' => mb_substr($name, 0, 255),
                'group_name' => isset($map['group']) ? (mb_substr(trim((string) ($raw[$map['group']] ?? '')), 0, 255) ?: null) : null,
                'discount_percent' => $discount,
                'source_file' => $originalName,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors, 'stats' => $stats];
    }

    /**
     * Сохранить разобранные строки и проставить скидки организациям.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{saved: int, matched: int, changed: int, unmatched: int}
     */
    public function apply(array $rows, ?User $by = null): array
    {
        if ($rows === []) {
            return ['saved' => 0, 'matched' => 0, 'changed' => 0, 'unmatched' => 0];
        }

        $organizations = Organization::query()
            ->whereIn('inn', array_column($rows, 'inn'))
            ->get(['id', 'inn', 'discount_percent'])
            ->keyBy('inn');

        $saved = $matched = $changed = 0;

        DB::transaction(function () use ($rows, $organizations, $by, &$saved, &$matched, &$changed) {
            foreach ($rows as $row) {
                $organization = $organizations[$row['inn']] ?? null;

                ClientDiscount::updateOrCreate(
                    ['inn' => $row['inn']],
                    $row + [
                        'organization_id' => $organization?->id,
                        'imported_by_user_id' => $by?->id,
                    ],
                );
                $saved++;

                if ($organization === null) {
                    continue;
                }
                $matched++;
                if ((float) $organization->discount_percent !== (float) $row['discount_percent']) {
                    $organization->update(['discount_percent' => $row['discount_percent']]);
                    $changed++;
                }
            }
        });

        return [
            'saved' => $saved,
            'matched' => $matched,
            'changed' => $changed,
            'unmatched' => $saved - $matched,
        ];
    }

    /**
     * Скидка клиента: сначала карточка организации, затем выгрузка по ИНН.
     * Выгрузка — источник истины, карточка лишь её копия для скорости.
     */
    public function discountFor(?Organization $organization): float
    {
        if ($organization === null) {
            return 0.0;
        }
        $own = (float) ($organization->discount_percent ?? 0);
        if ($own > 0) {
            return $own;
        }

        $inn = ClientDiscount::normalizeInn($organization->inn);

        return $inn === null
            ? 0.0
            : (float) (ClientDiscount::query()->where('inn', $inn)->value('discount_percent') ?? 0);
    }

    /**
     * Где какая колонка. Ищем по названию, приведённому к нижнему регистру без
     * пробелов: «СкидкаПроцент», «скидка %» и «Скидка, процент» — одно и то же.
     *
     * @param  array<int, mixed>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $title) {
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $title) ?? '');
            $field = match (true) {
                str_contains($key, 'контрагент') || str_contains($key, 'наименование') => 'name',
                str_contains($key, 'инн') => 'inn',
                str_contains($key, 'группа') => 'group',
                str_contains($key, 'скидка') => 'discount',
                default => null,
            };
            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }
}
