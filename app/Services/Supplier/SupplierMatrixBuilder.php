<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Prompts\Suppliers\BuildSupplierMatrixPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Сборка матрицы ассортимента поставщика из текстового описания (Фаза 3.1).
 * gpt-4o-mini → {brands, categories, pairs}. Сохраняет в supplier с пометкой
 * времени/модели. Fail-safe: при ошибке LLM матрицу не трогаем.
 */
class SupplierMatrixBuilder
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly BuildSupplierMatrixPrompt $prompt,
    ) {
    }

    /**
     * Построить и сохранить матрицу. Возвращает true при успехе.
     */
    public function rebuild(Supplier $supplier): bool
    {
        $description = trim((string) $supplier->assortment_description);
        if ($description === '') {
            // Нет описания — чистим матрицу.
            $supplier->forceFill([
                'assortment_matrix' => null,
                'matrix_built_at' => now(),
                'matrix_built_with_model' => null,
            ])->save();

            return true;
        }

        $model = config('services.openai.intent_model', 'gpt-4o-mini');

        try {
            $result = $this->openai->chat(
                $this->prompt->build($description),
                $model,
                [
                    'temperature' => 0,
                    'max_tokens' => 800,
                    'response_format' => ['type' => 'json_object'],
                ],
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

        $matrix = [
            'brands' => $this->cleanList($parsed['brands'] ?? []),
            'categories' => $this->cleanList($parsed['categories'] ?? []),
            'pairs' => $this->cleanPairs($parsed['pairs'] ?? []),
        ];

        $supplier->forceFill([
            'assortment_matrix' => $matrix,
            'matrix_built_at' => now(),
            'matrix_built_with_model' => $model,
        ])->save();

        return true;
    }

    /**
     * @param  mixed  $list
     * @return array<int, string>
     */
    private function cleanList($list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return collect($list)
            ->map(fn ($v) => is_string($v) ? trim($v) : '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $pairs
     * @return array<int, array{brand: string, category: string}>
     */
    private function cleanPairs($pairs): array
    {
        if (! is_array($pairs)) {
            return [];
        }

        $out = [];
        foreach ($pairs as $p) {
            if (! is_array($p)) {
                continue;
            }
            $brand = trim((string) ($p['brand'] ?? ''));
            $category = trim((string) ($p['category'] ?? ''));
            if ($brand !== '' && $category !== '') {
                $out[] = ['brand' => $brand, 'category' => $category];
            }
        }

        return $out;
    }
}
