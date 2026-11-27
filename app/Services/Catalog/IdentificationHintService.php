<?php

namespace App\Services\Catalog;

use App\Models\Identification\IdentificationHint;
use App\Models\Identification\IdentificationPartType;
use App\Models\RequestItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Что нужно, чтобы опознать деталь: тип детали по названию позиции и вопросы клиенту (справочник опознания —
 * таблицы identification_*, пополняется сам по письмам-уточнениям менеджеров, IdentificationLearner).
 *
 * Тип — по слову, которое стоит в названии раньше: главное слово обычно первое («Плата вызывного поста на 2
 * кнопки» — плата, «Цепь привода поручня» — цепь). При равенстве — порядок справочника (частное раньше общего:
 * «канатоведущий шкив» — шкив, не канат). Вопросы — что менеджеры спрашивают чаще, первыми.
 */
class IdentificationHintService
{
    private const CACHE_KEY = 'identification:types:v2';

    /**
     * @return array{key: string, name: string, hints: list<array{label: string, question: string}>}|null
     */
    public function forName(?string $name): ?array
    {
        return self::typeOf((string) $name, $this->types());
    }

    /**
     * Подсказка для позиции заявки. Позиция проверена (IdentificationCoverageService) — по каждому вопросу
     * отметка: known (данные уже есть: в названии, тексте клиента, прочитаны с фото), partial (неполно —
     * спросить ask), missing. Не проверена ещё — вопросы типа без отметок (status null).
     *
     * @return array{key: string, name: string, checked: bool, hints: list<array{label: string, question: string, status: ?string, value: ?string, source: ?string, ask: ?string}>}|null
     */
    public function forItem(RequestItem $item): ?array
    {
        $cov = $item->identification_coverage;
        if (is_array($cov) && array_key_exists('type', $cov)) {
            if (empty($cov['type']['key'])) {
                return null;
            }

            return ['key' => (string) $cov['type']['key'], 'name' => (string) $cov['type']['name'], 'checked' => true, 'hints' => array_values((array) ($cov['hints'] ?? []))];
        }
        $type = $this->forName($item->parsed_name);
        if ($type === null) {
            return null;
        }

        return ['key' => $type['key'], 'name' => $type['name'], 'checked' => false, 'hints' => array_map(
            fn ($h) => ['label' => $h['label'], 'question' => $h['question'], 'status' => null, 'value' => null, 'source' => null, 'ask' => null],
            $type['hints'],
        )];
    }

    /**
     * Что спросить у клиента: всё, кроме полученного; неполное — уточняющим вопросом.
     *
     * @param  array{hints: list<array<string, mixed>>}  $forItem
     * @return list<array{label: string, question: string}>
     */
    public static function toAsk(array $forItem): array
    {
        $out = [];
        foreach ($forItem['hints'] as $h) {
            if (($h['status'] ?? null) === 'known') {
                continue;
            }
            $ask = ($h['status'] ?? null) === 'partial' && trim((string) ($h['ask'] ?? '')) !== '' ? (string) $h['ask'] : (string) $h['question'];
            $out[] = ['label' => (string) $h['label'], 'question' => $ask];
        }

        return $out;
    }
    /**
     * Тип по названию среди переданных типов (без базы — для тестов и разбора писем).
     *
     * @param  list<array{key: string, name: string, pattern: ?string, hints: list<array{label: string, question: string}>}>  $types
     * @return array{key: string, name: string, hints: list<array{label: string, question: string}>}|null
     */
    public static function typeOf(string $name, array $types): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $best = null;
        $bestPos = PHP_INT_MAX;
        foreach ($types as $type) {
            $pattern = (string) ($type['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }
            $hit = @preg_match($pattern, $name, $m, PREG_OFFSET_CAPTURE);
            if ($hit === 1 && $m[0][1] < $bestPos) {
                $best = $type;
                $bestPos = $m[0][1];
            }
        }

        return $best === null ? null : ['key' => (string) $best['key'], 'name' => (string) $best['name'], 'hints' => array_values((array) $best['hints'])];
    }

    /**
     * Действующие типы с подсказками (из базы, кэш 10 минут; база пуста или недоступна — начальный справочник).
     *
     * @return list<array{id?: int, key: string, name: string, pattern: ?string, hints: list<array{label: string, question: string}>}>
     */
    public function types(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 600, function () {
                if (! Schema::hasTable('identification_part_types') || ! IdentificationPartType::query()->exists()) {
                    return self::seedTypes();
                }

                return IdentificationPartType::query()->where('is_active', true)->orderBy('sort')->orderBy('id')
                    ->with(['hints' => fn ($q) => $q->where('status', IdentificationHint::ACTIVE)->orderByDesc('asked_count')->orderBy('sort')->orderBy('id')])
                    ->get()
                    ->map(fn (IdentificationPartType $t) => [
                        'id' => $t->id, 'key' => $t->key, 'name' => $t->name, 'pattern' => $t->pattern,
                        'hints' => $t->hints->map(fn (IdentificationHint $h) => ['id' => $h->id, 'label' => $h->label, 'question' => $h->question, 'goal' => $h->goal])->values()->all(),
                    ])
                    ->filter(fn ($t) => $t['hints'] !== [])
                    ->values()->all();
            });
        } catch (\Throwable $e) {
            Log::warning('IdentificationHintService: types unavailable, using seed', ['error' => $e->getMessage()]);

            return self::seedTypes();
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Начальный справочник (config/identification_hints.php, свод писем 28.11.2026). */
    public static function seedTypes(): array
    {
        return array_values((array) config('identification_hints.types', []));
    }
}
