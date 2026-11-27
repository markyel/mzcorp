<?php

namespace App\Services\Catalog;

/**
 * Что нужно, чтобы опознать деталь (config/identification_hints.php, свод писем-уточнений менеджеров):
 * тип детали по названию позиции и готовые вопросы клиенту.
 *
 * Тип — по слову, которое стоит в названии раньше: главное слово обычно первое («Плата вызывного поста на 2
 * кнопки» — плата, «Цепь привода поручня» — цепь, «Колесо привода поручня» — колесо). При равенстве — порядок
 * в справочнике (частное раньше общего: «канатоведущий шкив» — шкив, не канат).
 */
class IdentificationHintService
{
    /**
     * @return array{key: string, name: string, hints: list<array{label: string, question: string}>}|null
     */
    public function forName(?string $name): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $best = null;
        $bestPos = PHP_INT_MAX;
        foreach ((array) config('identification_hints.types', []) as $type) {
            if (preg_match((string) $type['pattern'], $name, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] < $bestPos) {
                $best = $type;
                $bestPos = $m[0][1];
            }
        }

        return $best === null ? null : ['key' => (string) $best['key'], 'name' => (string) $best['name'], 'hints' => array_values((array) $best['hints'])];
    }
}
