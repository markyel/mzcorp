<?php

namespace App\Services\Supplier;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use App\Models\Supplier;
use App\Prompts\Suppliers\BuildSupplierMatrixPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Сборка матрицы ассортимента поставщика из текстового описания (Фаза 3.1).
 * Маппит на НАШУ таксономию (36 KB-категорий + 43 бренда) → matrix.categories
 * дословно совпадают с именами, которыми тегированы позиции → точный матч без
 * «неохваченных» из-за разных слов. Возвращённые LLM значения «приклеиваются»
 * к каноническим (закрытый список для категорий). Fail-safe.
 */
class SupplierMatrixBuilder
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly BuildSupplierMatrixPrompt $prompt,
    ) {
    }

    public function rebuild(Supplier $supplier): bool
    {
        $description = trim((string) $supplier->assortment_description);
        if ($description === '') {
            $supplier->forceFill([
                'assortment_matrix' => null,
                'matrix_built_at' => now(),
                'matrix_built_with_model' => null,
            ])->save();

            return true;
        }

        $categories = EquipmentCategory::query()->orderBy('name')->pluck('name')
            ->filter()->map(fn ($n) => (string) $n)->values()->all();
        $brands = ManufacturerBrand::query()->orderBy('name')->pluck('name')
            ->filter()->map(fn ($n) => (string) $n)->values()->all();

        $model = config('services.openai.intent_model', 'gpt-4o-mini');

        try {
            $result = $this->openai->chat(
                $this->prompt->build($description, $categories, $brands),
                $model,
                ['temperature' => 0, 'max_tokens' => 900, 'response_format' => ['type' => 'json_object']],
            );
        } catch (\Throwable $e) {
            Log::warning('SupplierMatrixBuilder: LLM call failed (non-fatal)', [
                'supplier_id' => $supplier->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed)) {
            return false;
        }

        // Канонические карты: норм-имя → каноническое имя.
        $catCanon = $this->canonMap($categories);
        $brandCanon = $this->canonMap($brands);

        // Категории — ЗАКРЫТЫЙ список: оставляем только сматченные на наши 36.
        $cats = $this->snapList($parsed['categories'] ?? [], $catCanon, dropUnmatched: true);
        // Бренды — предпочтительно наши написания, но чужие не выкидываем.
        $brandsOut = $this->snapList($parsed['brands'] ?? [], $brandCanon, dropUnmatched: false);

        $pairs = [];
        foreach ((array) ($parsed['pairs'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $b = $this->snapOne((string) ($p['brand'] ?? ''), $brandCanon, false);
            $c = $this->snapOne((string) ($p['category'] ?? ''), $catCanon, true);
            if ($b !== null && $c !== null) {
                $pairs[] = ['brand' => $b, 'category' => $c];
            }
        }

        $supplier->forceFill([
            'assortment_matrix' => ['brands' => $brandsOut, 'categories' => $cats, 'pairs' => $pairs],
            'matrix_built_at' => now(),
            'matrix_built_with_model' => $model,
        ])->save();

        return true;
    }

    /**
     * @param  array<int, string>  $canonical
     * @return array<string, string>  норм → каноническое
     */
    private function canonMap(array $canonical): array
    {
        $map = [];
        foreach ($canonical as $name) {
            $map[$this->norm($name)] = $name;
        }

        return $map;
    }

    /**
     * @param  mixed  $list
     * @param  array<string, string>  $canon
     * @return array<int, string>
     */
    private function snapList($list, array $canon, bool $dropUnmatched): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $v) {
            if (! is_string($v)) {
                continue;
            }
            $snapped = $this->snapOne($v, $canon, $dropUnmatched);
            if ($snapped !== null && ! in_array($snapped, $out, true)) {
                $out[] = $snapped;
            }
        }

        return $out;
    }

    /**
     * Приклеить значение к каноническому (точный норм-матч → стем по префиксу).
     * dropUnmatched=false вернёт исходное trimmed-значение, если не сматчилось.
     *
     * @param  array<string, string>  $canon
     */
    private function snapOne(string $value, array $canon, bool $dropUnmatched): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $n = $this->norm($value);
        if (isset($canon[$n])) {
            return $canon[$n];
        }
        // стем-фоллбэк: общий префикс первого слова ≥4
        foreach ($canon as $cn => $canonical) {
            if ($this->stemEq($n, $cn)) {
                return $canonical;
            }
        }

        return $dropUnmatched ? null : $value;
    }

    private function stemEq(string $a, string $b): bool
    {
        $fa = preg_split('/\s+/u', $a)[0] ?? $a;
        $fb = preg_split('/\s+/u', $b)[0] ?? $b;
        $la = mb_strlen($fa);
        $lb = mb_strlen($fb);
        if ($la < 4 || $lb < 4) {
            return $fa === $fb;
        }
        $p = 0;
        $min = min($la, $lb);
        while ($p < $min && mb_substr($fa, $p, 1) === mb_substr($fb, $p, 1)) {
            $p++;
        }

        return $p >= 4 && $p >= $min - 2;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[«»"„“”()]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }
}
