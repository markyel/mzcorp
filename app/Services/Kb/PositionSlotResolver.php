<?php

namespace App\Services\Kb;

use App\Models\Kb\IdentificationParameter;
use App\Models\Kb\IdentificationRule;
use App\Models\RequestItem;
use Illuminate\Support\Collection;

/**
 * Foundation §«Что переиспользуется» (KB) + §6.2 — resolve slots
 * (заполненные параметры идентификации) для одной позиции.
 *
 * Вызов: `resolve(RequestItem)` возвращает упорядоченный массив slot
 * descriptors. Каждый slot имеет ключ, label, value (или null), status
 * (filled / empty), source (parsed / enriched / kb / catalog / null),
 * required (для UI «обязательный» маркера).
 *
 * Источники slot значений:
 *  - Базовые слоты (Категория, Бренд, Артикул, Кол-во, Цена) —
 *    из parsed_*/catalogItem/identification_category напрямую.
 *  - KB-слоты — из `IdentificationRule.alternatives.required_parameter_ids`
 *    для категории item.identification_category_id. Значения берутся
 *    из `quality_assessment_payload.extracted_parameters[slug]`.
 *
 * Кэш: per-request (через property) — повторные вызовы для разных items
 * с одинаковой категорией не плодят SQL.
 */
class PositionSlotResolver
{
    /**
     * Базовые слоты — всегда показываются, в фиксированном порядке.
     * Slot 'category' использует identification_category_id если есть,
     * иначе извлекаем из parsed_brand/parsed_name (вне scope).
     */
    private const BASE_SLOTS = [
        'category' => ['label' => 'Категория', 'required' => false],
        'brand' => ['label' => 'Бренд', 'required' => true],
        'article' => ['label' => 'Артикул', 'required' => true],
        'qty' => ['label' => 'Кол-во', 'required' => true],
    ];

    /** @var array<int, array<int, IdentificationParameter>> */
    private array $kbParamsCache = [];

    /**
     * @return array<int, array{
     *   key: string,
     *   label: string,
     *   value: ?string,
     *   status: 'filled'|'empty',
     *   source: ?string,
     *   required: bool,
     *   question_template: ?string,
     * }>
     */
    public function resolve(RequestItem $item): array
    {
        $slots = [];

        // 1. Base slots
        $catName = $item->kbCategory?->name ?? null;
        $slots[] = $this->slot('category', self::BASE_SLOTS['category']['label'], $catName, 'kb', false);

        $brandName = $item->brand?->name ?? trim((string) ($item->parsed_brand ?? ''));
        $brandSource = $item->brand_id ? 'kb' : ($brandName !== '' ? 'parsed' : null);
        $slots[] = $this->slot('brand', self::BASE_SLOTS['brand']['label'], $brandName ?: null, $brandSource, true);

        $article = trim((string) ($item->parsed_article ?? ''));
        $slots[] = $this->slot('article', self::BASE_SLOTS['article']['label'], $article ?: null, $article !== '' ? 'parsed' : null, true);

        $qty = $item->parsed_qty
            ? rtrim(rtrim((string) $item->parsed_qty, '0'), '.') . ' ' . ($item->parsed_unit ?: 'шт.')
            : null;
        $slots[] = $this->slot('qty', self::BASE_SLOTS['qty']['label'], $qty, $qty ? 'parsed' : null, true);

        // 2. KB-derived slots — на основе identification_category_id
        $extracted = is_array($item->quality_assessment_payload['extracted_parameters'] ?? null)
            ? $item->quality_assessment_payload['extracted_parameters']
            : [];

        if ($item->identification_category_id) {
            $kbParams = $this->kbParametersForCategory((int) $item->identification_category_id);
            foreach ($kbParams as $param) {
                // Skip параметры, которые конфликтуют с base-slots (например слот
                // article если в KB есть «артикул» как параметр).
                if (in_array($param->slug, ['article', 'brand', 'category', 'qty'], true)) {
                    continue;
                }
                $value = $extracted[$param->slug] ?? null;
                $valueStr = $this->stringifyExtractedValue($value, $param->unit);
                $slots[] = $this->slot(
                    'kb:' . $param->slug,
                    $param->name ?: $param->slug,
                    $valueStr,
                    $valueStr ? 'enriched' : null,
                    false,
                    $param->question_template,
                );
            }
        }

        return $slots;
    }

    /**
     * Подсчёт filled/total по уже resolved slot list.
     *
     * @param  array<int, array>  $slots
     * @return array{filled: int, total: int, percent: int}
     */
    public function progress(array $slots): array
    {
        $total = count($slots);
        $filled = 0;
        foreach ($slots as $s) {
            if (($s['status'] ?? null) === 'filled') {
                $filled++;
            }
        }

        return [
            'filled' => $filled,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($filled * 100 / $total) : 0,
        ];
    }

    /**
     * Aggregate per-request: суммарный progress по всем active items.
     *
     * @param  Collection<int, RequestItem>  $items
     * @return array{filled: int, total: int, percent: int}
     */
    public function aggregateProgress(Collection $items): array
    {
        $filled = 0;
        $total = 0;
        foreach ($items as $item) {
            if (! $item->is_active) {
                continue;
            }
            $p = $this->progress($this->resolve($item));
            $filled += $p['filled'];
            $total += $p['total'];
        }

        return [
            'filled' => $filled,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($filled * 100 / $total) : 0,
        ];
    }

    /**
     * KB-параметры для категории: union(required_parameter_ids) по всем
     * active alternatives всех active rules. Кэшируется per-category.
     *
     * @return array<int, IdentificationParameter>
     */
    private function kbParametersForCategory(int $categoryId): array
    {
        if (isset($this->kbParamsCache[$categoryId])) {
            return $this->kbParamsCache[$categoryId];
        }

        $rules = IdentificationRule::query()
            ->with('alternatives')
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->get();

        $paramIds = [];
        foreach ($rules as $rule) {
            foreach ($rule->alternatives as $alt) {
                $ids = is_array($alt->required_parameter_ids) ? $alt->required_parameter_ids : [];
                foreach ($ids as $id) {
                    $paramIds[(int) $id] = true;
                }
            }
        }

        if (empty($paramIds)) {
            return $this->kbParamsCache[$categoryId] = [];
        }

        $params = IdentificationParameter::query()
            ->whereIn('id', array_keys($paramIds))
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->all();

        return $this->kbParamsCache[$categoryId] = $params;
    }

    private function slot(
        string $key,
        string $label,
        ?string $value,
        ?string $source,
        bool $required,
        ?string $questionTemplate = null,
    ): array {
        $filled = $value !== null && trim($value) !== '';

        return [
            'key' => $key,
            'label' => $label,
            'value' => $filled ? $value : null,
            'status' => $filled ? 'filled' : 'empty',
            'source' => $filled ? $source : null,
            'required' => $required,
            'question_template' => $questionTemplate,
        ];
    }

    private function stringifyExtractedValue(mixed $value, ?string $unit): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $str = trim((string) ($value['value'] ?? ''));
            if ($str === '') {
                $str = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        } elseif (is_bool($value)) {
            $str = $value ? 'да' : 'нет';
        } else {
            $str = trim((string) $value);
        }
        if ($str === '') {
            return null;
        }
        if ($unit !== null && $unit !== '' && ! str_ends_with($str, $unit)) {
            $str .= ' ' . $unit;
        }

        return $str;
    }
}
