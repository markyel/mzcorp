<?php

namespace App\Services\Kb;

use App\Models\ArticleCodeInsight;
use App\Services\AI\OpenAIChatService;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Support\Facades\Log;

/**
 * «Что это за код и чей» — единая точка разбора кодов позиций.
 *
 * Порядок: (1) KB-маски brand_sku_patterns (мгновенно, точность ~100%) →
 * (2) кэш article_code_insights (ранее разобранные ИИ/вручную) → (3) null,
 * кандидат на фоновый ИИ-разбор (analyzeBatch, вызывается job'ом).
 *
 * Отвечает и на вопрос «а это вообще OEM-артикул?» — kind=model/internal/
 * fragment помечает коды, которые парсер извлёк из маркировки товара или
 * внутренних номеров клиента: их бессмысленно искать в каталоге точным матчем.
 */
class ArticleInsightService
{
    public function __construct(
        private readonly ArticleClassificationService $classifier,
        private readonly OpenAIChatService $chat,
    ) {
    }

    /**
     * Разбор одного кода без ИИ-вызова: KB-маска или кэш. Null = ещё не разобран.
     */
    public function resolve(string $rawCode): ?ArticleCodeInsight
    {
        $norm = self::normalize($rawCode);
        if ($norm === null) {
            return null;
        }

        // KB-маска — самый надёжный источник, кэш не нужен (мгновенно).
        $kb = $this->classifier->classify($rawCode);
        if (($kb['type'] ?? null) === 'manufacturer_sku') {
            return new ArticleCodeInsight([
                'code_normalized' => $norm,
                'raw_sample' => $rawCode,
                'kind' => ArticleCodeInsight::KIND_OEM,
                'manufacturer_name' => $kb['matched_brand_name'] ?? null,
                'manufacturer_brand_id' => $kb['matched_brand_id'] ?? null,
                'confidence' => $kb['confidence'] ?? 0.95,
                'series_hint' => $kb['matched_series'] ?? null,
                'source' => 'kb_pattern',
            ]);
        }

        return ArticleCodeInsight::query()->where('code_normalized', $norm)->first();
    }

    /**
     * Массовый lookup для страницы отчёта: raw-код → insight (KB/кэш).
     *
     * @param  array<int, string>  $rawCodes
     * @return array<string, ArticleCodeInsight>  ключ — исходный raw-код
     */
    public function resolveMany(array $rawCodes): array
    {
        $normMap = [];
        foreach ($rawCodes as $raw) {
            $norm = self::normalize($raw);
            if ($norm !== null) {
                $normMap[$raw] = $norm;
            }
        }
        $cached = ArticleCodeInsight::query()
            ->whereIn('code_normalized', array_values(array_unique($normMap)))
            ->get()
            ->keyBy('code_normalized');

        $out = [];
        foreach ($normMap as $raw => $norm) {
            $kb = $this->classifier->classify($raw);
            if (($kb['type'] ?? null) === 'manufacturer_sku') {
                $out[$raw] = new ArticleCodeInsight([
                    'kind' => ArticleCodeInsight::KIND_OEM,
                    'manufacturer_name' => $kb['matched_brand_name'] ?? null,
                    'confidence' => $kb['confidence'] ?? 0.95,
                    'series_hint' => $kb['matched_series'] ?? null,
                    'source' => 'kb_pattern',
                ]);
            } elseif ($cached->has($norm)) {
                $out[$raw] = $cached[$norm];
            }
        }

        return $out;
    }

    /**
     * Фоновый ИИ-разбор пачки кодов (вызывается из AnalyzeArticleCodesJob).
     * Контекст (название/марка из заявок) повышает точность. Результаты
     * сохраняются в article_code_insights (upsert по нормализованному коду).
     *
     * @param  array<int, array{code: string, name?: ?string, brand?: ?string}>  $items
     * @return int сколько кодов сохранено
     */
    public function analyzeBatch(array $items): int
    {
        $saved = 0;
        foreach (array_chunk($items, 25) as $chunk) {
            $lines = [];
            foreach ($chunk as $c) {
                $lines[] = $c['code']
                    .' | название: '.mb_substr((string) ($c['name'] ?? ''), 0, 80)
                    .' | марка в заявке: '.(($c['brand'] ?? '') !== '' ? $c['brand'] : '—');
            }
            try {
                $resp = $this->chat->chat([
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => "Коды:\n".implode("\n", $lines)],
                ], 'gpt-4o', ['temperature' => 0, 'response_format' => ['type' => 'json_object'], 'max_tokens' => 4000]);
                $parsed = $this->extractItems(json_decode((string) $resp['content'], true));
            } catch (\Throwable $e) {
                Log::warning('ArticleInsight: LLM batch failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

                continue;
            }

            foreach ($parsed as $x) {
                $raw = trim((string) ($x['code'] ?? ''));
                $norm = self::normalize($raw);
                if ($norm === null) {
                    continue;
                }
                $kind = in_array($x['kind'] ?? '', ['oem', 'model', 'internal', 'fragment'], true)
                    ? $x['kind'] : ArticleCodeInsight::KIND_UNKNOWN;
                $manufacturer = trim((string) ($x['manufacturer'] ?? '')) ?: null;
                ArticleCodeInsight::updateOrCreate(
                    ['code_normalized' => $norm],
                    [
                        'raw_sample' => mb_substr($raw, 0, 190),
                        'kind' => $kind,
                        'manufacturer_name' => $manufacturer !== null ? mb_substr($manufacturer, 0, 120) : null,
                        'manufacturer_brand_id' => $manufacturer !== null ? $this->matchBrandId($manufacturer) : null,
                        'confidence' => is_numeric($x['confidence'] ?? null) ? round((float) $x['confidence'], 2) : null,
                        'series_hint' => ($x['series_hint'] ?? null) !== null ? mb_substr((string) $x['series_hint'], 0, 190) : null,
                        'source' => 'llm',
                        'analyzed_at' => now(),
                    ],
                );
                $saved++;
            }
        }

        return $saved;
    }

    /** Нормализация как в матчинге каталога. */
    public static function normalize(string $raw): ?string
    {
        $norm = CatalogImportService::normalizeArticle($raw);

        return ($norm !== null && mb_strlen($norm) >= 3) ? $norm : null;
    }

    private function matchBrandId(string $name): ?int
    {
        return \App\Models\Kb\ManufacturerBrand::query()
            ->whereRaw('lower(name) like ?', ['%'.mb_strtolower(mb_substr($name, 0, 12)).'%'])
            ->value('id');
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Ты — эксперт по запчастям для лифтов и эскалаторов (OTIS, Schindler, KONE, ThyssenKrupp, Fermator, Wittur/Selcom, Sigma, XIZI, отечественные КМЗ/ЩЛЗ/МЛЗ и др.).
Для каждого кода определи:
- kind: "oem" (артикул производителя), "model" (обозначение модели/серии из маркировки товара, не складской артикул), "internal" (внутренний код клиента/поставщика), "fragment" (обрывок/не код);
- manufacturer: производитель, которому принадлежит формат кода (или null);
- confidence: 0..1;
- series_hint: узнаваемая серия/формат кратко (или null).
Опирайся на формат кода и контекст. Не выдумывай: незнакомый формат — manufacturer=null, confidence ниже.
Ответ — строго JSON вида {"items":[{"code":"...","kind":"...","manufacturer":null,"confidence":0.0,"series_hint":null}]}.
TXT;
    }

    /** Найти в декодированном JSON первый массив объектов с ключом 'code'. */
    private function extractItems(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        if (isset($node[0]) && is_array($node[0]) && array_key_exists('code', $node[0])) {
            return $node;
        }
        foreach ($node as $v) {
            $found = $this->extractItems($v);
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }
}
