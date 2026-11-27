<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\RequestItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Варианты одной модели по НАЗВАНИЮ: карточки каталога с тем же главным словом и почти тем же названием
 * («Кнопка вызова KLEEMANN FMR BS … "ВВЕРХ" / "ВНИЗ" / "1" / "2"»), которые отличаются существенным признаком —
 * надписью или символом кнопки, стороной, цветом, комплектацией «с … / без …».
 *
 * Подбор по названию, выбрав карточку, сверяет эти признаки с текстом клиента: клиент назвал признак, и он
 * указывает на соседнюю карточку — берём её (модель-реранк выбрала «ВНИЗ», а клиент просил «ВВЕРХ»); клиент
 * признака не назвал, а соседи им различаются — не угадываем, а пишем, чего не хватает. Отличия вроде страны
 * производства или кода поставщика признаком не считаются.
 *
 * Общий артикул производителя для этого не годится: у одной серии кнопок он то общий, то свой у каждой
 * (проверка 27.11.2026: группы по артикулу не исправили ни одной ошибки).
 */
class CatalogSiblingService
{
    /** Насколько похожим должно быть название соседа (pg_trgm similarity). */
    public const MIN_SIMILARITY = 0.6;

    /** Признаки: вопрос клиенту и вес совпадения (направление различает кнопки сильнее слова «стрелка»). */
    private const FEATURES = [
        'label' => ['name' => 'надпись / символ', 'question' => 'Какой символ или надпись (этаж, направление, функция)?'],
        'side' => ['name' => 'сторона', 'question' => 'Левое или правое исполнение?'],
        'color' => ['name' => 'цвет', 'question' => 'Какой цвет?'],
        'presence' => ['name' => 'комплектация', 'question' => 'В каком исполнении — с чем или без чего?'],
        'braille' => ['name' => 'шрифт Брайля', 'question' => 'С шрифтом Брайля или без?'],
    ];

    private const DIRECTION = ['ВВЕРХ', 'ВНИЗ', 'ВЛЕВО', 'ВПРАВО'];

    /** Признаки, у которых «не сказано» значит обычное исполнение. */
    private const DEFAULT_WHEN_UNSAID = ['color', 'presence', 'braille'];

    private const GENERIC_LABEL = ['СТРЕЛКА', 'СТРЕЛКИ', 'СИМВОЛ'];

    private const LABEL_WORDS = ['ОТКРЫТИЕ', 'ЗАКРЫТИЕ', 'ВЕНТИЛЯТОР', 'ДИСПЕТЧЕР', 'ДИСПЕТЧЕРА', 'ВЫЗОВ', 'ЗВОНОК', 'СТОП', 'ОТМЕНА', 'ПОЖАРНАЯ', 'ПАРКОВКА'];

    /**
     * Сверить выбранную карточку с текстом клиента по признакам её соседей.
     *
     * @return array{catalog: ?CatalogItem, missing: list<array{name: string, question: string, options: list<string>}>, changed: bool}
     */
    public function resolve(RequestItem $item, CatalogItem $picked): array
    {
        $keep = ['catalog' => $picked, 'missing' => [], 'changed' => false];
        $siblings = $this->siblings($picked);
        if ($siblings->isEmpty()) {
            return $keep;
        }

        $client = self::features(trim(((string) $item->parsed_name).' '.((string) $item->parsed_article)), false);
        $members = collect([$picked])->merge($siblings)->map(fn (CatalogItem $c) => ['catalog' => $c, 'f' => self::features((string) $c->name, true)])->values();
        // Признаки, которыми члены группы вообще различаются.
        $varying = array_values(array_filter(array_keys(self::FEATURES), fn ($k) => $members->map(fn ($m) => self::key($m['f'][$k]['values']))->unique()->count() > 1));
        if ($varying === []) {
            return $keep;
        }

        $remaining = $members;
        foreach ($varying as $k) {
            $want = $client[$k]['values'];
            if ($want === []) {
                continue;
            }
            // Карточки, у которых всё, что сказано про признак, есть у клиента; лучшие — по весу совпадения.
            $scored = $remaining->map(function ($m) use ($k, $want) {
                $have = $m['f'][$k]['values'];
                if ($have === [] || array_diff($have, $want) !== []) {
                    return null;
                }

                return $m + ['score' => array_sum(array_map(fn ($v) => self::weight($k, $v), array_intersect($have, $want)))];
            })->filter();
            if ($scored->isNotEmpty()) {
                $best = $scored->max('score');
                $remaining = $scored->filter(fn ($m) => $m['score'] === $best)->values();
            }
        }

        // Коды исполнения латиницей (разъём MLEP / MLK): клиент назвал код, который есть не у всех соседей, —
        // берём тех, у кого он есть. Вопроса по кодам не задаём: часто это несущественные обозначения.
        $codes = $remaining->map(fn ($m) => self::codes((string) $m['catalog']->name));
        $varCodes = array_values(array_diff(array_unique(array_merge(...$codes->all())), array_intersect(...array_values($codes->all() + [[]]))));
        $said = array_values(array_intersect($varCodes, self::codes(trim(((string) $item->parsed_name).' '.((string) $item->parsed_article)))));
        if ($said !== [] && $remaining->count() > 1) {
            // Больше совпавших кодов — ближе: WECO-917L71 (два кода клиента) сильнее KMS…-P220 (один).
            $hits = $remaining->map(fn ($m) => count(array_intersect($said, self::codes((string) $m['catalog']->name))));
            if ($hits->max() > 0) {
                $best = $hits->max();
                $remaining = $remaining->filter(fn ($m, $i) => $hits[$i] === $best)->values();
            }
        }

        // Сменить карточку может только признак, который клиент назвал (выше). Признак, о котором клиент молчит,
        // карточку не меняет — лишь иногда даёт «не хватает»: «демаркационная линия жёлтая» не уходит к
        // соседу без цвета, «шкив без …» — к соседу без оговорки (проверка 28.11.2026).
        $ids = $remaining->pluck('catalog.id')->all();
        $pickedIn = in_array($picked->id, $ids, true);
        $pickedF = self::features((string) $picked->name, true);
        $missing = [];
        foreach ($varying as $k) {
            if ($client[$k]['values'] !== []) {
                continue;
            }
            $kinds = $remaining->map(fn ($m) => self::key($m['f'][$k]['values']))->unique();
            $options = $remaining->map(fn ($m) => $m['f'][$k]['display'])->filter()->unique()->values();
            if ($kinds->count() <= 1 || $options->isEmpty()) {
                continue;
            }
            // Выбрана карточка обычного исполнения (без цвета, стороны, оговорок) — её и оставляем.
            if ($pickedIn && $pickedF[$k]['values'] === []) {
                continue;
            }
            // «С … / без …» без слова клиента не спрашиваем; цвет — только когда у всех вариантов он свой
            // (кнопки с белой / красной / голубой подсветкой), а не «обычная или жёлтая».
            if (in_array($k, self::DEFAULT_WHEN_UNSAID, true)
                && ($k === 'presence' || $remaining->contains(fn ($m) => $m['f'][$k]['values'] === []))) {
                continue;
            }
            $missing[] = self::FEATURES[$k] + ['options' => $options->take(15)->all()];
        }
        if ($missing !== []) {
            Log::info('CatalogSiblingService: variant not determined by client text', [
                'request_item_id' => $item->id, 'picked' => $picked->sku, 'remaining' => count($ids), 'missing' => array_column($missing, 'name'),
            ]);

            return ['catalog' => null, 'missing' => $missing, 'changed' => false];
        }
        if (in_array($picked->id, $ids, true)) {
            return $keep;
        }
        // Страховка: не уходим к соседу с размером (число от 10), которого нет ни у клиента, ни в выбранной
        // карточке, — «цепь 1000 мм» не становится «800 мм» из-за совпавшего кода (проверка 28.11.2026).
        $allowed = array_merge(self::numbers(trim(((string) $item->parsed_name).' '.((string) $item->parsed_article))), self::numbers((string) $picked->name));
        $safe = $remaining->filter(fn ($m) => array_diff(self::numbers((string) $m['catalog']->name), $allowed) === [])->values();
        if ($safe->isEmpty()) {
            return $keep;
        }
        /** @var CatalogItem $chosen */
        $chosen = $safe->first()['catalog'];

        return ['catalog' => $chosen, 'missing' => [], 'changed' => true];
    }

    /**
     * Соседи: то же главное слово, похожее название.
     *
     * @return Collection<int, CatalogItem>
     */
    public function siblings(CatalogItem $picked, int $limit = 30): Collection
    {
        $name = mb_strtolower((string) $picked->name);
        $head = self::head((string) $picked->name);
        if ($name === '' || $head === '') {
            return collect();
        }
        $rows = DB::select(
            'select id, similarity(lower(name), ?) as s from catalog_items
             where is_active = true and id <> ? and lower(name) % ? and similarity(lower(name), ?) >= ?
             order by s desc limit ?',
            [$name, $picked->id, $name, $name, self::MIN_SIMILARITY, $limit],
        );
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        if ($ids === []) {
            return collect();
        }
        $byId = CatalogItem::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId[$id] ?? null)->filter(fn ($c) => $c !== null && self::head((string) $c->name) === $head)->values();
    }

    /**
     * Существенные признаки текста: values — сравнимые значения, display — как написано.
     *
     * @return array<string, array{values: list<string>, display: ?string}>
     */
    public static function features(string $text, bool $catalogName): array
    {
        $up = mb_strtoupper(str_replace(['ё', 'Ё'], 'Е', $text));
        $out = array_fill_keys(array_keys(self::FEATURES), ['values' => [], 'display' => null]);

        // Надпись / символ кнопки: в кавычках; у клиента — ещё «этаж 2», «символ 1», «маркировкой 7» и слова-надписи.
        $labels = [];
        if (preg_match_all('/["«“„]([^"»”“]{1,40})["»”“]/u', $up, $m)) {
            $labels = array_merge($labels, $m[1]);
        }
        if (! $catalogName && preg_match_all('/(?:ЭТАЖ\p{L}*|СИМВОЛ\p{L}*|МАРКИРОВК\p{L}*|№)\s*(-?\d{1,2})(?![\d\p{L}])/u', $up, $m)) {
            $labels = array_merge($labels, $m[1]);
        }
        if (! $catalogName && preg_match_all('/(?<![\d\p{L}-])(-?\d{1,2})\s*(?:-?Й\s+)?ЭТАЖ/u', $up, $m)) {
            $labels = array_merge($labels, $m[1]); // «17 этаж», «2-й этаж»
        }
        $words = self::tokens($up);
        $labelTokens = [];
        foreach ($labels as $l) {
            foreach (self::tokens($l) as $t) {
                $labelTokens[] = $t;
            }
        }
        foreach ($words as $t) {
            $t = self::labelWord($t);
            if (in_array($t, self::DIRECTION, true) || in_array($t, self::LABEL_WORDS, true)) {
                $labelTokens[] = $t;
            }
        }
        $labelTokens = array_map(fn ($t) => self::labelWord($t), $labelTokens);
        // Надпись — номер этажа, слово-надпись или короткий символ; «Обь», «Лифт-Комплекс» в кавычках — не надпись.
        $labelTokens = array_values(array_unique(array_filter($labelTokens, fn ($t) => preg_match('/^-?\d{1,2}$/u', $t) === 1
            || in_array($t, self::DIRECTION, true) || in_array($t, self::LABEL_WORDS, true) || in_array($t, self::GENERIC_LABEL, true)
            || (mb_strlen($t) <= 2 && preg_match('/\p{L}/u', $t) === 1) || in_array($t, ['>I<', '<I>', '<>', '><'], true))));
        $out['label'] = ['values' => $labelTokens, 'display' => $labels !== [] ? trim($labels[0]) : ($labelTokens !== [] ? implode(' ', $labelTokens) : null)];

        // Сторона.
        foreach ($words as $t) {
            if (preg_match('/^(ЛЕВ(ЫЙ|АЯ|ОЕ|ОГО|ОЙ|ЫЕ)?|LH|LEFT)$/u', $t)) {
                $out['side'] = ['values' => ['L'], 'display' => mb_strtolower($t)];
            } elseif (preg_match('/^(ПРАВ(ЫЙ|АЯ|ОЕ|ОГО|ОЙ|ЫЕ)?|RH|RIGHT)$/u', $t)) {
                $out['side'] = ['values' => ['R'], 'display' => mb_strtolower($t)];
            } elseif (preg_match('/^(ЦЕНТРАЛЬН\p{L}*|CENTER|CENTRE)$/u', $t)) {
                $out['side'] = ['values' => ['C'], 'display' => mb_strtolower($t)];
            }
        }

        // Цвет.
        $colors = [];
        $display = null;
        foreach ($words as $t) {
            $c = self::color($t);
            if ($c !== null) {
                $colors[] = $c;
                $display ??= mb_strtolower($t);
            }
        }
        $out['color'] = ['values' => array_values(array_unique($colors)), 'display' => $display];

        // Шрифт Брайля: «с кодом Брайля», «с Брайлем», braille.
        if (preg_match('/БРАЙЛ|BRAILLE/u', $up) === 1) {
            $out['braille'] = ['values' => ['Y'], 'display' => 'с Брайлем'];
        }

        // Комплектация «с …» / «без …»: слово от 5 букв после предлога, по основе.
        if (preg_match_all('/(?<![\p{L}])(БЕЗ|С|СО)\s+(\p{L}{5,})/u', $up, $m, PREG_SET_ORDER)) {
            $vals = [];
            $disp = null;
            foreach ($m as $hit) {
                $neg = $hit[1] === 'БЕЗ' ? 'БЕЗ' : 'С';
                $vals[] = $neg.' '.mb_substr($hit[2], 0, 6);
                $disp ??= mb_strtolower($hit[1].' '.$hit[2]);
            }
            $out['presence'] = ['values' => array_values(array_unique($vals)), 'display' => $disp];
        }

        return $out;
    }

    /** Слово-надпись в любом падеже → как в каталоге: «закрытия» → ЗАКРЫТИЕ, «диспетчеру» → ДИСПЕТЧЕР. */
    private static function labelWord(string $t): string
    {
        // Только формы существительного: «диспетчерского», «открытый» — не надпись.
        foreach ([
            '/^ЗАКРЫТИ\p{L}*$/u' => 'ЗАКРЫТИЕ', '/^ОТКРЫТИ\p{L}*$/u' => 'ОТКРЫТИЕ', '/^ВЕНТИЛЯТОР(А|У|ОМ)?$/u' => 'ВЕНТИЛЯТОР',
            '/^ДИСПЕТЧЕР(А|У|ОМ)?$/u' => 'ДИСПЕТЧЕР', '/^ПАРКОВК(А|И|У|ОЙ)$/u' => 'ПАРКОВКА', '/^ОТМЕН(А|Ы|У)$/u' => 'ОТМЕНА',
            '/^ЗВОН(ОК|КА|КУ)$/u' => 'ЗВОНОК',
        ] as $re => $canon) {
            if (preg_match($re, $t) === 1) {
                return $canon;
            }
        }

        return $t;
    }

    private static function weight(string $feature, string $value): float
    {
        if ($feature !== 'label') {
            return 1.0;
        }
        if (in_array($value, self::GENERIC_LABEL, true)) {
            return 0.3;
        }

        return in_array($value, self::DIRECTION, true) ? 2.0 : 1.0;
    }

    private static function color(string $t): ?string
    {
        foreach ([
            'КРАСН' => '/^(КРАСН\p{L}*|КРАС)$/u', 'ЖЕЛТ' => '/^ЖЕЛТ\p{L}*$/u', 'ЗЕЛЕН' => '/^ЗЕЛЕН\p{L}*$/u',
            'СИН' => '/^СИН(ИЙ|ЯЯ|ЕЕ|ЕЙ|ЕГО|ИЕ)$/u', 'ГОЛУБ' => '/^ГОЛУБ\p{L}*$/u', 'БЕЛ' => '/^БЕЛ(ЫЙ|АЯ|ОЕ|ОЙ|ЫЕ|ОГО)$/u',
            'ЧЕРН' => '/^ЧЕРН\p{L}*$/u', 'ЯНТАР' => '/^ЯНТАР\p{L}*$/u', 'ОРАНЖ' => '/^ОРАНЖ\p{L}*$/u',
        ] as $key => $re) {
            if (preg_match($re, $t) === 1) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Коды латиницей из названия (MLEP, MLK, FMR, 3VF): от 2 знаков, хотя бы одна латинская буква.
     *
     * @return list<string>
     */
    private static function codes(string $text): array
    {
        $up = mb_strtoupper($text);

        // «WECO-917L71-AC220», «KMS-B154/B1-P220» — по частям.
        $parts = preg_split('/[^A-Z0-9.]+/', $up) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($t) => preg_match('/^[A-Z0-9][A-Z0-9.]*$/', $t) === 1
            && preg_match('/[A-Z]/', $t) === 1 && strlen($t) >= 2)));
    }

    /**
     * Числа от 10 — размеры, длины, количества ступеней: «1000мм», «76x22», «OP900».
     *
     * @return list<string>
     */
    private static function numbers(string $text): array
    {
        preg_match_all('/(?<![\d.,])(\d{2,5})(?![\d])/u', $text, $m);

        return array_values(array_unique(array_map(fn ($n) => (string) (int) $n, $m[1])));
    }

    /** @return list<string> */
    private static function tokens(string $up): array
    {
        $parts = preg_split('/[^\p{L}\p{N}<>\-]+/u', $up) ?: [];
        // «-1» — подземный этаж, минус оставляем; у слов дефисы по краям срезаем.
        $parts = array_map(fn ($p) => preg_match('/^-\d+$/u', $p) === 1 ? $p : trim($p, '-'), $parts);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    private static function head(string $name): string
    {
        $t = self::tokens(mb_strtoupper(str_replace(['ё', 'Ё'], 'Е', $name)));

        return $t[0] ?? '';
    }

    /** @param  list<string>  $values */
    private static function key(array $values): string
    {
        sort($values);

        return implode('|', $values);
    }
}
