<?php

namespace App\Services\Marketing;

use App\Models\PressOutlet;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Загрузка базы СМИ из таблицы (лист «СМИ», колонки как в press_media.xlsx).
 *
 * Ключ — адрес сайта: повторная загрузка обновляет площадку, а не плодит
 * дубль. Отключённую руками площадку загрузка обратно не включает.
 */
class PressOutletImporter
{
    /** Заголовок колонки → поле. */
    private const COLUMNS = [
        'Приоритет' => 'priority',
        'Категория' => 'category',
        'Площадка' => 'name',
        'Тип' => 'kind',
        'Сайт' => 'url',
        'Email' => 'emails',
        'Другой канал' => 'other_contact',
        'Условия' => 'terms',
        'Последняя публикация' => 'last_post',
        'Аудитория' => 'audience',
        'Заметки' => 'notes',
        'Где взят email' => 'email_source',
    ];

    /**
     * @return array{created: int, updated: int, skipped: list<string>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $sheet = IOFactory::load($path)->getSheetByName('СМИ');
        if ($sheet === null) {
            throw new \RuntimeException('В файле нет листа «СМИ».');
        }

        $rows = $sheet->toArray(null, true, false, false);
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);
        $map = [];
        foreach ($header as $i => $title) {
            if (isset(self::COLUMNS[$title])) {
                $map[$i] = self::COLUMNS[$title];
            }
        }
        if (! in_array('url', $map, true) || ! in_array('name', $map, true)) {
            throw new \RuntimeException('Нет колонок «Площадка» и «Сайт».');
        }

        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($rows as $n => $cells) {
            $row = [];
            foreach ($map as $i => $field) {
                $row[$field] = trim((string) ($cells[$i] ?? ''));
            }
            if (($row['url'] ?? '') === '' || ($row['name'] ?? '') === '') {
                $skipped[] = 'строка '.($n + 2).': нет сайта или названия';

                continue;
            }

            $attrs = $this->attributes($row);
            $outlet = PressOutlet::where('url', $attrs['url'])->first();
            if ($dryRun) {
                $outlet === null ? $created++ : $updated++;

                continue;
            }

            if ($outlet === null) {
                PressOutlet::create($attrs + ['is_active' => true]);
                $created++;
            } else {
                $outlet->fill($attrs)->save();
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row): array
    {
        $priority = strtoupper(mb_substr($row['priority'] ?? '', 0, 1));
        $category = $row['category'] ?? '';
        $notes = $row['notes'] ?? '';
        if (($row['last_post'] ?? '') !== '') {
            $notes = trim('Последняя публикация на момент сбора: '.$row['last_post'].".\n".$notes);
        }

        return [
            'name' => mb_substr($row['name'], 0, 200),
            'category' => in_array($category, PressOutlet::CATEGORIES, true) ? $category : 'Деловые и промышленные СМИ',
            'priority' => array_key_exists($priority, PressOutlet::PRIORITIES) ? $priority : 'B',
            'kind' => $this->nullable($row['kind'] ?? '', 160),
            'url' => mb_substr($row['url'], 0, 500),
            'emails' => $this->emails($row['emails'] ?? '') ?: null,
            'other_contact' => $this->nullable($row['other_contact'] ?? ''),
            'terms' => $this->nullable($row['terms'] ?? '', 500),
            'audience' => $this->nullable($row['audience'] ?? ''),
            'notes' => $this->nullable($notes),
            'email_source' => $this->nullable($row['email_source'] ?? ''),
        ];
    }

    /** @return list<string> */
    private function emails(string $raw): array
    {
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $raw, $m);
        $list = array_values(array_unique(array_map('strtolower', $m[0])));
        usort($list, fn ($a, $b) => (int) preg_match(PressOutlet::COMMERCIAL_EMAIL, $a) <=> (int) preg_match(PressOutlet::COMMERCIAL_EMAIL, $b));

        return $list;
    }

    private function nullable(string $value, ?int $max = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }
}
