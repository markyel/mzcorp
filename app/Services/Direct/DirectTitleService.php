<?php

namespace App\Services\Direct;

use App\Models\CatalogItem;
use App\Models\DirectAdTitle;
use App\Models\User;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Заголовки объявлений: правила по умолчанию, модель — там, где правилам плохо.
 *
 * Модель не имеет права ничего придумывать: всё, что она пишет, обязано
 * встречаться в карточке позиции. Выдуманная совместимость («подходит для
 * KONE») в рекламе запчастей — это претензия клиента и отказ модерации,
 * поэтому ответ проверяется: каждый латинский/цифровой токен заголовка должен
 * найтись в исходных данных, иначе результат отбрасывается и остаётся правило.
 */
class DirectTitleService
{
    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {}

    /** Сохранённые заголовки по артикулам. @return array<string, DirectAdTitle> */
    public function storedFor(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return DirectAdTitle::query()->whereIn('sku', $skus)->get()->keyBy('sku')->all();
    }

    /**
     * Сгенерировать заголовок моделью и сохранить. Возвращает заголовок или
     * null, если модель не дала ничего пригодного (тогда работает правило).
     */
    public function generate(object $item, ?User $by = null): ?DirectAdTitle
    {
        $catalogItem = CatalogItem::query()->where('sku', (string) $item->sku)->first(['id', 'sku', 'name']);
        if ($catalogItem === null) {
            return null;
        }

        $model = (string) config('services.yandex_direct.title_model', 'gpt-4o-mini');
        $ruleTitle = DirectAdPlanService::adTitle($item);

        try {
            $res = $this->openai->chat([
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user', 'content' => self::userPrompt($item, $ruleTitle)],
            ], $model, ['temperature' => 0.2, 'max_tokens' => 120]);
        } catch (\Throwable $e) {
            Log::warning('Direct: заголовок не сгенерирован', ['sku' => $item->sku, 'error' => $e->getMessage()]);

            return null;
        }

        $candidate = self::cleanAnswer((string) ($res['content'] ?? ''));
        if ($candidate === null || ! self::isFaithful($candidate, $item)) {
            Log::info('Direct: заголовок модели отклонён', [
                'sku' => $item->sku, 'candidate' => $candidate,
            ]);

            return null;
        }

        return $this->save($catalogItem, $candidate, DirectAdTitle::SOURCE_AI, $by, $model);
    }

    /** Сохранить заголовок (правка руками или результат модели). */
    public function save(CatalogItem $item, string $title, string $source, ?User $by, ?string $model = null): DirectAdTitle
    {
        return DirectAdTitle::updateOrCreate(
            ['catalog_item_id' => $item->id],
            [
                'sku' => $item->sku,
                'title' => DirectAdPlanService::tidyTail(mb_substr(trim($title), 0, DirectAdPlanService::TITLE_MAX)),
                'source' => $source,
                'model' => $model,
                'source_name' => $item->name,
                'created_by_user_id' => $by?->id,
            ],
        );
    }

    public function forget(string $sku): bool
    {
        return DirectAdTitle::query()->where('sku', $sku)->delete() > 0;
    }

    /** Ответ модели: одна строка без кавычек, точки и лишних пояснений. */
    public static function cleanAnswer(string $raw): ?string
    {
        $line = trim(preg_split('/\R/u', trim($raw))[0] ?? '');
        // Кавычки и точку снимаем регуляркой, а не trim(): trim режет по байтам
        // и на «ёлочках» способен откусить половину кириллической буквы.
        $line = preg_replace('/^[\s"\'«»`]+|[\s"\'«»`.]+$/u', '', $line) ?? '';
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

        if ($line === '' || mb_strlen($line) < 8) {
            return null;
        }
        if (mb_strlen($line) > DirectAdPlanService::TITLE_MAX) {
            $line = DirectAdPlanService::cutWords($line, DirectAdPlanService::TITLE_MAX);
        }

        return DirectAdPlanService::tidyTail($line);
    }

    /**
     * Проверка на выдумку: латиница и цифры в заголовке должны встречаться в
     * карточке позиции. Русские слова не проверяем — их модель переформулирует
     * («резиновый» → «для эскалатора»), а вот «KONE» или «GO50AEX» из воздуха
     * взяться не должны.
     */
    public static function isFaithful(string $title, object $item): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($item->name ?? ''),
            (string) ($item->brand ?? ''),
            (string) ($item->brand_article ?? ''),
            (string) ($item->part_type ?? ''),
            (string) ($item->sku ?? ''),
            implode(' ', DirectAdPlanService::codes($item)),
        ])));
        $haystack = preg_replace('/[^a-z0-9а-я]+/u', '', $haystack) ?? '';

        foreach (preg_split('/[^A-Za-z0-9]+/u', $title) ?: [] as $token) {
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            $needle = mb_strtolower($token);
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
        Ты пишешь заголовки объявлений Яндекс.Директа для магазина запчастей к лифтам и эскалаторам.

        Правила:
        1. Только факты из карточки товара. НИЧЕГО не добавляй: ни совместимость, ни бренды,
           ни модели оборудования, которых нет в данных.
        2. Максимум 56 символов. Короче — лучше.
        3. Сначала что это за деталь, затем бренд или артикул производителя, если они есть.
        4. Без цен, без «!», без КАПСА, без кавычек, без точки в конце.
        5. Не обрывай слова и не оставляй открытых скобок.
        6. Ответ — ровно одна строка с заголовком, без пояснений.
        TXT;
    }

    private static function userPrompt(object $item, string $ruleTitle): string
    {
        $codes = DirectAdPlanService::codes($item);

        return implode("\n", array_filter([
            'Название в каталоге: '.(string) ($item->name ?? ''),
            ($item->brand ?? '') ? 'Бренд: '.$item->brand : '',
            $codes !== [] ? 'Артикулы производителя: '.implode(', ', array_slice($codes, 0, 5)) : '',
            ($item->part_type ?? '') ? 'Категория: '.$item->part_type : '',
            '',
            'Автоматический вариант (обрезан по длине): '.$ruleTitle,
            'Напиши заголовок лучше, уложившись в 56 символов.',
        ]));
    }
}
