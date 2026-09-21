<?php

namespace App\Services\Direct;

use App\Models\CatalogItem;
use App\Models\DirectAdText;
use App\Models\User;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Тексты объявлений: правила по умолчанию, модель — когда хотим рекламный
 * текст вместо складской формулировки.
 *
 * Пишем весь набор сразу (заголовок, второй заголовок, текст) и ЗАРАНЕЕ, для
 * всей очереди, а не только для позиций в ротации: каждое изменение текста у
 * работающего объявления отправляет его на повторную модерацию, поэтому
 * вычитывать тексты надо до публикации.
 *
 * Модель не имеет права ничего придумывать: всё, что она пишет, обязано
 * встречаться в карточке позиции. Выдуманная совместимость («подходит для
 * KONE») в рекламе запчастей — это претензия клиента и отказ модерации,
 * поэтому каждое поле проверяется отдельно и негодное просто отбрасывается —
 * на его месте остаётся вариант правила.
 */
class DirectAdTextService
{
    /** Лимиты Директа по полям — по ним же режем ответ модели. */
    public const LIMITS = [
        'title' => DirectAdPlanService::TITLE_MAX,
        'title2' => DirectAdPlanService::TITLE2_MAX,
        'text' => DirectAdPlanService::TEXT_MAX,
    ];

    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {}

    /**
     * Сохранённые тексты по артикулам.
     *
     * @return array<string, DirectAdText>
     */
    public function storedFor(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return DirectAdText::query()->whereIn('sku', $skus)->get()->keyBy('sku')->all();
    }

    /**
     * Написать объявление моделью и сохранить. Возвращает запись или null,
     * если ничего пригодного не получилось (тогда работают правила).
     */
    public function generate(object $item, ?User $by = null, ?string $tone = null): ?DirectAdText
    {
        $catalogItem = CatalogItem::query()->where('sku', (string) $item->sku)->first(['id', 'sku', 'name']);
        if ($catalogItem === null) {
            return null;
        }

        $tone = DirectAdTone::normalize($tone);
        $model = (string) config('services.yandex_direct.title_model', 'gpt-4o-mini');
        $rule = [
            'title' => DirectAdPlanService::adTitle($item),
            'title2' => DirectAdPlanService::adTitle2($item),
            'text' => DirectAdPlanService::adText($item),
        ];

        try {
            $res = $this->openai->chat([
                ['role' => 'system', 'content' => self::systemPrompt($tone)],
                ['role' => 'user', 'content' => self::userPrompt($item, $rule)],
            ], $model, [
                'temperature' => DirectAdTone::temperature($tone),
                'max_tokens' => 300,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Direct: объявление не сгенерировано', ['sku' => $item->sku, 'error' => $e->getMessage()]);

            return null;
        }

        $answer = self::decode((string) ($res['content'] ?? ''));
        if ($answer === null) {
            Log::info('Direct: ответ модели не разобран', ['sku' => $item->sku, 'raw' => $res['content'] ?? '']);

            return null;
        }

        // Порядок важен: второй заголовок проверяется против принятого первого.
        $accepted = [];
        $dropped = [];
        foreach (self::LIMITS as $field => $max) {
            $against = $field === 'title2' ? ($accepted['title'] ?? $rule['title']) : null;
            $value = self::acceptField((string) ($answer[$field] ?? ''), $field, $item, $tone, $against);
            if ($value === null) {
                $dropped[] = $field;

                continue;
            }
            $accepted[$field] = $value;
        }

        if ($dropped !== []) {
            Log::info('Direct: поля объявления отклонены', [
                'sku' => $item->sku, 'dropped' => $dropped, 'answer' => $answer,
            ]);
        }
        if ($accepted === []) {
            return null;
        }

        // Пакетная генерация идёт по десяткам позиций: сбой на одной не должен
        // ронять весь проход — пропускаем её и идём дальше.
        try {
            return $this->save($catalogItem, $accepted, DirectAdText::SOURCE_AI, $by, $model, $tone);
        } catch (\Throwable $e) {
            Log::error('Direct: объявление не сохранено', [
                'sku' => $item->sku, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Сохранить тексты. Переданные поля перезаписываются, остальные остаются
     * как были — правка одного заголовка не должна стирать вычитанный текст.
     *
     * @param  array<string, string>  $fields
     */
    public function save(
        CatalogItem $item,
        array $fields,
        string $source,
        ?User $by,
        ?string $model = null,
        ?string $tone = null,
    ): DirectAdText {
        $payload = [
            'sku' => $item->sku,
            'source' => $source,
            'model' => $model,
            'tone' => $tone,
            'source_name' => $item->name,
            'created_by_user_id' => $by?->id,
        ];

        foreach (DirectAdText::FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $value = trim((string) $fields[$field]);
            $payload[$field] = $value === ''
                ? null
                : DirectAdPlanService::tidyTail(mb_substr($value, 0, self::LIMITS[$field]));
        }

        return DirectAdText::updateOrCreate(['catalog_item_id' => $item->id], $payload);
    }

    public function forget(string $sku): bool
    {
        return DirectAdText::query()->where('sku', $sku)->delete() > 0;
    }

    /**
     * Разобрать ответ модели. Просили JSON, но ставить на это всё нельзя:
     * если пришла просто строка, считаем её заголовком.
     *
     * @return array<string, string>|null
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // JSON бывает завёрнут в ```json … ``` — вырезаем содержимое фигурных скобок.
        if (preg_match('/\{.*\}/su', $raw, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $decoded);
            }
        }

        $line = self::cleanAnswer($raw, self::LIMITS['title']);

        return $line === null ? null : ['title' => $line];
    }

    /**
     * Поле, годное к публикации, или null — тогда останется вариант правила.
     *
     * $against — уже принятый заголовок: второй заголовок пристраивается к
     * нему в выдаче и повторять его слова не должен.
     */
    public static function acceptField(
        string $raw,
        string $field,
        object $item,
        ?string $tone = null,
        ?string $against = null,
    ): ?string {
        $value = self::cleanAnswer($raw, self::LIMITS[$field] ?? DirectAdPlanService::TITLE_MAX);
        if ($value === null) {
            return null;
        }
        if (! self::isFaithful($value, $item)) {
            return null;
        }
        if (self::violatesRules($value, $field, $tone, $item)) {
            return null;
        }
        if ($field === 'title2' && self::isTautology($value, $against)) {
            return null;
        }

        return $value;
    }

    /**
     * Повтор внутри фразы или повтор первого заголовка. Кейс M00073: второй
     * заголовок «Собранный контакт в сборе» — и тавтология, и слово «контакт»
     * уже сказано в первом заголовке; тридцать символов потрачены впустую.
     *
     * Сравниваем согласный скелет слова: «собранный» → «сбрннй», «сборе» →
     * «сбр». Русские чередования («сбор» / «собр») простое усечение основы не
     * ловит, а скелет ловит.
     */
    public static function isTautology(string $value, ?string $against = null): bool
    {
        $own = self::skeletons($value);
        foreach ($own as $i => $a) {
            foreach (array_slice($own, $i + 1) as $b) {
                if (self::sameRoot($a, $b)) {
                    return true;
                }
            }
        }

        foreach (self::skeletons((string) $against) as $b) {
            foreach ($own as $a) {
                if (self::sameRoot($a, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Согласные скелеты значимых русских слов фразы.
     *
     * @return array<int, string>
     */
    private static function skeletons(string $value): array
    {
        $out = [];
        foreach (preg_split('/[^\p{Cyrillic}]+/u', mb_strtolower($value)) ?: [] as $word) {
            if (mb_strlen($word) < 5) {
                continue;
            }
            $skeleton = preg_replace('/[аеёиоуыэюяйъь]/u', '', $word) ?? '';
            if (mb_strlen($skeleton) >= 3) {
                $out[] = $skeleton;
            }
        }

        return $out;
    }

    private static function sameRoot(string $a, string $b): bool
    {
        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;

        return mb_strlen($short) >= 3 && str_starts_with($long, $short);
    }

    /** Ответ модели: одна строка без кавычек, точки-хвоста и лишних пояснений. */
    public static function cleanAnswer(string $raw, int $max = DirectAdPlanService::TITLE_MAX): ?string
    {
        $line = trim(preg_split('/\R/u', trim($raw))[0] ?? '');
        // Кавычки снимаем регуляркой, а не trim(): trim режет по байтам и на
        // «ёлочках» способен откусить половину кириллической буквы.
        $line = preg_replace('/^[\s"\'«»`]+|[\s"\'«»`.]+$/u', '', $line) ?? '';
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

        if ($line === '' || mb_strlen($line) < 4) {
            return null;
        }
        if (mb_strlen($line) > $max) {
            $line = DirectAdPlanService::cutWords($line, $max);
        }

        return DirectAdPlanService::tidyTail($line) ?: null;
    }

    /**
     * Проверка на выдумку: латиница и цифры в тексте должны встречаться в
     * карточке позиции. Русские слова не проверяем — их модель переформулирует
     * («резиновый» → «для эскалатора»), а вот «KONE» или «GO50AEX» из воздуха
     * взяться не должны.
     */
    public static function isFaithful(string $value, object $item): bool
    {
        $haystack = self::cardHaystack($item);

        foreach (preg_split('/[^A-Za-z0-9]+/u', $value) ?: [] as $token) {
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            if (! str_contains($haystack, mb_strtolower($token))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Бренд и артикулы — единственное, что законно писать заглавными: МЕЧЕЛ,
     * ЩЛЗ, ГОСТ, УИРФ. Всё прочее заглавными — крик.
     */
    private static function brandHaystack(object $item): string
    {
        $raw = mb_strtolower(implode(' ', array_filter([
            (string) ($item->brand ?? ''),
            (string) ($item->brand_article ?? ''),
            implode(' ', DirectAdPlanService::codes($item)),
        ])));

        return preg_replace('/[^a-z0-9а-я]+/u', '', $raw) ?? '';
    }

    /** Всё, что известно о позиции, одной строкой без разделителей. */
    private static function cardHaystack(object $item): string
    {
        $raw = mb_strtolower(implode(' ', array_filter([
            (string) ($item->name ?? ''),
            (string) ($item->brand ?? ''),
            (string) ($item->brand_article ?? ''),
            (string) ($item->part_type ?? ''),
            (string) ($item->sku ?? ''),
            implode(' ', DirectAdPlanService::codes($item)),
        ])));

        return preg_replace('/[^a-z0-9а-я]+/u', '', $raw) ?? '';
    }

    /**
     * То, за что снимают с модерации или за что придётся отвечать: цена в
     * тексте (её на карточке анонимному посетителю не видно), превосходная
     * степень без доказательств, КАПС и лишние восклицания.
     *
     * $item нужен для капса: МЕЧЕЛ, УТОС, ГОСТ, УИРФ — это настоящие бренды и
     * обозначения из карточки, а не крик. Заглавное слово законно, если оно в
     * карточке есть; выдуманное — нет.
     */
    public static function violatesRules(string $value, string $field, ?string $tone = null, ?object $item = null): bool
    {
        // Цена и скидки — решение заказчика: рекламируем наличие, не цену.
        if (preg_match('/(₽|\bруб\b|\bруб\.|\bцена\b|\bцены\b|скидк|распродаж|дешевл|бесплатн)/iu', $value)) {
            return true;
        }
        // Превосходная степень и обещания, которые модерация требует доказать.
        if (preg_match('/(лучш|самый|самая|самое|самые|№\s?1|номер\s?один|гаранти|100\s?%|круглосуточн)/iu', $value)) {
            return true;
        }
        if (self::shouts($value, $item)) {
            return true;
        }

        $exclamations = mb_substr_count($value, '!');
        if ($field !== 'text' && $exclamations > 0) {
            return true;
        }

        return $exclamations > (DirectAdTone::allowsExclamation($tone) ? 1 : 0);
    }

    /**
     * Крик капсом. Латиница не в счёт — OTIS и FCU пишутся так по делу.
     * Кириллическое слово капсом законно, если оно есть в карточке (МЕЧЕЛ,
     * ЩЛЗ, ГОСТ, УИРФ), и незаконно, если модель написала капсом обычное слово
     * или набрала капсом всю строку.
     */
    public static function shouts(string $value, ?object $item = null): bool
    {
        if (! preg_match_all('/[А-ЯЁ]{4,}/u', $value, $m)) {
            return false;
        }

        // Сверяем ТОЛЬКО с брендом и артикулами. Каталожные названия сами
        // написаны с криком («Коннектор С РАЗЪЕМАМИ тяговых ремней»), и
        // сверка с именем такой капс легализовала — Директ отклонил (M07484).
        $haystack = $item !== null ? self::brandHaystack($item) : '';
        foreach ($m[0] as $token) {
            if ($haystack === '' || ! str_contains($haystack, mb_strtolower($token))) {
                return true;
            }
        }

        // Все слова из карточки, но строка целиком набрана капсом — это уже крик.
        $letters = preg_match_all('/\p{Cyrillic}/u', $value);
        $upper = preg_match_all('/[А-ЯЁ]/u', $value);

        return $letters >= 8 && $upper / max(1, $letters) > 0.7;
    }

    private static function systemPrompt(string $tone): string
    {
        $limits = self::LIMITS;
        $toneLine = 'Тон объявления: '.DirectAdTone::label($tone).'. '.DirectAdTone::prompt($tone);

        return <<<TXT
        Ты пишешь объявления для поиска Яндекс.Директа. Рекламодатель — поставщик запчастей
        к лифтам и эскалаторам, товар лежит на складе и отгружается сразу.

        {$toneLine}

        Правила, которые важнее тона:
        1. Только факты из карточки товара. НИЧЕГО не добавляй: ни совместимость, ни бренды,
           ни модели оборудования, ни размеры, которых нет в данных.
        2. Длина строго: заголовок до {$limits['title']} символов, второй заголовок до
           {$limits['title2']}, текст до {$limits['text']}. Короче — лучше.
        3. Никаких цен, сумм, скидок и слова «бесплатно».
        4. Никакой превосходной степени («лучший», «самый», «№1») и слова «гарантия» —
           модерация требует это доказывать.
        5. Без КАПСА и без точки в конце заголовков.
        6. Заголовок — что это за деталь, с брендом и артикулом производителя, если они есть.
        7. Второй заголовок показывается сразу после первого, через разделитель, поэтому он
           обязан добавлять НОВОЕ. Пиши в нём одно из: бренд (если его нет в первом заголовке),
           узел лифта или эскалатора, где деталь стоит, или срок отгрузки. Запрещено повторять
           слова первого заголовка и повторять слово внутри самого второго заголовка:
           «Собранный контакт в сборе» — так нельзя.
        8. Текст — выгода покупателю: есть на складе, счёт в день обращения, отгрузка сразу.
        9. Не обрывай слова и не оставляй открытых скобок. Лучше короче, чем обрубок.

        Ответ — только JSON вида {"title": "…", "title2": "…", "text": "…"}, без пояснений.
        TXT;
    }

    /**
     * @param  array<string, string>  $rule
     */
    private static function userPrompt(object $item, array $rule): string
    {
        $codes = DirectAdPlanService::codes($item);

        return implode("\n", array_filter([
            'Название в каталоге: '.(string) ($item->name ?? ''),
            ($item->brand ?? '') ? 'Бренд: '.$item->brand : '',
            $codes !== [] ? 'Артикулы производителя: '.implode(', ', array_slice($codes, 0, 5)) : '',
            ($item->part_type ?? '') ? 'Категория: '.$item->part_type : '',
            (int) ($item->stock_available ?? 0) > 0 ? 'На складе: есть' : '',
            '',
            'Автоматический вариант, собранный по шаблону:',
            'title: '.$rule['title'],
            'title2: '.$rule['title2'],
            'text: '.$rule['text'],
            '',
            'Напиши лучше, уложившись в лимиты.',
        ]));
    }
}
