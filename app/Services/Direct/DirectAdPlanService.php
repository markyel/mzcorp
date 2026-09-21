<?php

namespace App\Services\Direct;

use App\Models\DirectAdText;
use App\Services\Catalog\YandexDirectFeedService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * План публикации: во что превращается позиция каталога, прежде чем уйти в
 * Директ. Ничего никуда не отправляет — только собирает тексты, фразы и ссылку
 * и проверяет их против ограничений Директа, чтобы отказ модерации не стал
 * сюрпризом после создания сотни объектов.
 *
 * Цену в объявлениях НЕ пишем (решение заказчика): на карточке сайта цены
 * анонимному посетителю не видно, и расхождение текста со страницей ни к чему.
 * Взамен — наличие и срок отгрузки, это правда и это то, чем мы отличаемся.
 */
class DirectAdPlanService
{
    /** Ограничения Директа на тексты. */
    public const TITLE_MAX = 56;

    public const TITLE2_MAX = 30;

    public const TEXT_MAX = 81;

    /** Ключевая фраза: не длиннее этого и не больше 7 слов. */
    public const KEYWORD_MAX = 4096;

    public const KEYWORD_MAX_WORDS = 7;

    /** Сколько фраз оставляем группе: больше — размывает статистику позиции. */
    public const KEYWORDS_PER_GROUP = 8;

    public function __construct(
        private readonly DirectCandidateService $candidates,
        private readonly DirectAdTextService $texts,
    ) {}

    /**
     * План по очереди. $limit — сколько позиций реально уйдёт в ротацию,
     * $depth — насколько глубоко готовим тексты: вычитывать их надо ДО
     * публикации, иначе правка у работающего объявления = повторная модерация.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(int $limit, ?int $depth = null): Collection
    {
        $depth = max($limit, $depth ?? $limit);
        $items = $this->candidates->queue($depth)->take($depth);
        $stored = $this->texts->storedFor($items->pluck('sku')->map(fn ($s) => (string) $s)->all());
        $tone = self::currentTone();

        return $items->values()->map(fn ($item, $i) => $this->forItem(
            $item,
            $stored[(string) $item->sku] ?? null,
            $i < $limit,
            $tone,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function forItem(
        object $item,
        ?DirectAdText $stored = null,
        bool $inRotation = true,
        ?string $tone = null,
    ): array {
        $keywords = self::keywords($item);
        $stored = $stored !== null && $stored->hasAnything() ? $stored : null;

        $rule = [
            'title' => self::adTitle($item),
            'title2' => '',
            'text' => self::adText($item),
        ];
        $title = trim((string) ($stored?->title ?? '')) ?: $rule['title'];
        // Второй заголовок зависит от первого — он не должен его повторять.
        $rule['title2'] = self::adTitle2($item, $title);

        $fields = [];
        $sources = [];
        foreach (DirectAdText::FIELDS as $field) {
            $own = trim((string) ($stored?->{$field} ?? ''));
            $fields[$field] = $own !== '' ? $own : $rule[$field];
            $sources[$field] = $own !== '' ? ($stored?->source ?? DirectAdText::SOURCE_RULE) : DirectAdText::SOURCE_RULE;
        }

        $warnings = [];
        if ($keywords === []) {
            $warnings[] = 'Нет кодов производителя — рекламировать нечем, фразы пустые.';
        }
        // Замечание снимается, как только заголовок переписан моделью или руками.
        if ($sources['title'] === DirectAdText::SOURCE_RULE && mb_strlen((string) ($item->name ?? '')) > self::TITLE_MAX) {
            $warnings[] = 'Название длиннее заголовка — обрезано, стоит переписать.';
        }
        if ($stored?->isStale((string) ($item->name ?? ''))) {
            $warnings[] = 'Позицию переименовали в каталоге — тексты могли устареть.';
        }
        if ($tone !== null && $stored?->isOtherTone($tone)) {
            $warnings[] = 'Написано прежним тоном — «'.DirectAdTone::label($stored->tone).'».';
        }

        return [
            'sku' => (string) ($item->sku ?? ''),
            'name' => (string) ($item->name ?? ''),
            'brand' => (string) ($item->brand ?? ''),
            'group' => self::groupName($item),
            'in_rotation' => $inRotation,
            'title' => $fields['title'],
            'title2' => $fields['title2'],
            'text' => $fields['text'],
            'rule' => $rule,
            'sources' => $sources,
            // Источник объявления целиком: правило, пока ни одно поле не тронуто.
            'source' => $stored?->source ?? DirectAdText::SOURCE_RULE,
            'tone' => $stored?->tone,
            'url' => YandexDirectFeedService::productUrl((string) ($item->sku ?? '')),
            'keywords' => $keywords,
            'warnings' => $warnings,
            'price' => (float) ($item->price ?? 0),
            'stock' => (int) ($item->stock_available ?? 0),
        ];
    }

    /** Текущий тон рекламы из настроек. */
    public static function currentTone(): string
    {
        return DirectAdTone::normalize(
            app(SettingsService::class)->get('direct.ad_tone', DirectAdTone::DEFAULT),
        );
    }

    /** Имя кампании-контейнера. */
    public static function campaignName(): string
    {
        return (string) config('services.yandex_direct.campaign_name', 'Склад — запчасти (авто)');
    }

    /** Имя группы: артикул впереди, чтобы группа находилась поиском по SKU. */
    public static function groupName(object $item): string
    {
        $sku = (string) ($item->sku ?? '');
        $name = trim((string) ($item->name ?? ''));

        return Str::limit(trim($sku.' '.$name), 55, '');
    }

    /**
     * Заголовок: сначала суть позиции, в хвосте — «в наличии», если помещается.
     * Директ режет длинные заголовки сам, но резать по словам лучше нам.
     */
    public static function adTitle(object $item): string
    {
        $base = trim((string) ($item->name ?? ''));
        if ($base === '') {
            $base = trim(((string) ($item->brand ?? '')).' '.((string) ($item->sku ?? '')));
        }
        $suffix = ' — в наличии';

        if (mb_strlen($base) + mb_strlen($suffix) <= self::TITLE_MAX) {
            return $base.$suffix;
        }

        return self::cutWords($base, self::TITLE_MAX);
    }

    /**
     * Второй заголовок — место для того, чего нет в первом: бренда или узла
     * лифта. Один и тот же текст на всех объявлениях выглядит как шаблон и
     * ничего не добавляет, поэтому берём первый подходящий вариант, который
     * НЕ повторяет первый заголовок и влезает в лимит.
     */
    public static function adTitle2(object $item, ?string $title = null): string
    {
        $title = mb_strtolower($title ?? self::adTitle($item));
        $brand = trim((string) ($item->brand ?? ''));
        $unit = self::shortPartType($item);

        $candidates = [];
        // Бренда нет в заголовке — он самый полезный второй заголовок.
        if ($brand !== '' && ! str_contains($title, mb_strtolower($brand))) {
            $candidates[] = $brand.' · со склада';
            $candidates[] = $brand;
        }
        // Иначе — узел: «Поручень эскалатора», «Ролик двери кабины».
        if ($unit !== '' && ! str_contains($title, mb_strtolower(mb_substr($unit, 0, 12)))) {
            $candidates[] = $unit.' · в наличии';
            $candidates[] = $unit;
        }
        $candidates[] = 'Отгрузка со склада';
        $candidates[] = 'Есть на складе';

        foreach ($candidates as $variant) {
            $variant = trim($variant);
            if ($variant !== '' && mb_strlen($variant) <= self::TITLE2_MAX) {
                return $variant;
            }
        }

        return 'Есть на складе';
    }

    /**
     * Узел из каталожной категории: «Поручень эскалатора и траволатора» →
     * «Поручень эскалатора». Категории длинные и с перечислениями — берём
     * начало до первого разделителя.
     */
    public static function shortPartType(object $item): string
    {
        $raw = trim((string) ($item->part_type ?? ''));
        if ($raw === '') {
            return '';
        }
        // Режем по первому разделителю перечисления.
        $head = preg_split('/[,(\/]| и | или /u', $raw)[0] ?? $raw;
        $head = trim($head, " \t.-–—");

        // Режем по самому лимиту второго заголовка: суффикс «· в наличии»
        // подставляется только если после него текст всё ещё помещается,
        // иначе узел уходит один — целым словом, а не обрубком.
        return self::cutWords(Str::ucfirst($head), self::TITLE2_MAX);
    }

    /** Текст: бренд, артикул производителя и обещание, которое мы держим. */
    public static function adText(object $item): string
    {
        $codes = self::codes($item);
        $parts = array_filter([
            trim((string) ($item->brand ?? '')),
            $codes !== [] ? 'арт. '.$codes[0] : '',
        ]);
        $head = $parts !== [] ? implode(', ', $parts).'. ' : '';
        $tail = 'Есть на складе, счёт в день обращения.';

        $text = $head.$tail;
        if (mb_strlen($text) <= self::TEXT_MAX) {
            return $text;
        }

        // Голова длиннее лимита — оставляем обещание, оно важнее бренда.
        return self::cutWords($head, self::TEXT_MAX - mb_strlen($tail) - 1).' '.$tail;
    }

    /**
     * Ключевые фразы группы — коды производителя позиции. Узкая фраза по коду
     * и есть смысл всей затеи: дешёвый клик от того, кто ищет ровно эту деталь.
     *
     * @return array<int, string>
     */
    public static function keywords(object $item): array
    {
        $out = [];
        foreach (self::codes($item) as $code) {
            $phrase = self::normalizeKeyword($code);
            if ($phrase === null) {
                continue;
            }
            $out[$phrase] = true;
            // «купить» отсекает читателей инструкций и оставляет покупателей.
            $withBuy = $phrase.' купить';
            if (self::normalizeKeyword($withBuy) !== null) {
                $out[$withBuy] = true;
            }
        }

        return array_slice(array_keys($out), 0, self::KEYWORDS_PER_GROUP);
    }

    /**
     * Фраза, пригодная для Директа: без служебных символов, не длиннее лимита
     * и не больше семи слов. Негодная — null.
     */
    public static function normalizeKeyword(string $phrase): ?string
    {
        // В фразах Директа нельзя !@#$%^*()_=;:'"<>,?/\| — чистим, дефис и
        // точку оставляем: они часть артикулов (XO-508, 6210-2RS1).
        $clean = preg_replace('/[^\p{L}\p{N}\s.\-+]/u', ' ', $phrase) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ($clean === '' || mb_strlen($clean) > self::KEYWORD_MAX) {
            return null;
        }
        if (mb_strlen($clean) < 3) {
            return null;
        }
        if (count(explode(' ', $clean)) > self::KEYWORD_MAX_WORDS) {
            return null;
        }

        return mb_strtolower($clean);
    }

    /**
     * Коды производителя позиции — те же, что уходят в фид.
     *
     * @return array<int, string>
     */
    public static function codes(object $item): array
    {
        return YandexDirectFeedService::codes($item);
    }

    /** Обрезать по границе слова, чтобы не оставлять половину артикула. */
    public static function cutWords(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return self::tidyTail($value);
        }
        $cut = mb_substr($value, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return self::tidyTail($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut);
    }

    /**
     * Прибрать хвост после обрезки: незакрытая скобка и висящая пунктуация.
     * Кейс «Фотозавеса динамическая FCU 0735X (FS0735) (24V DC, 35» — скобка
     * открылась, закрывающая не поместилась, объявление читается как обрывок.
     */
    public static function tidyTail(string $value): string
    {
        $value = trim($value);

        // Отрезаем незакрытые скобки вместе с их содержимым.
        foreach ([['(', ')'], ['[', ']']] as [$open, $close]) {
            while (mb_substr_count($value, $open) > mb_substr_count($value, $close)) {
                $pos = mb_strrpos($value, $open);
                if ($pos === false) {
                    break;
                }
                $value = rtrim(mb_substr($value, 0, $pos));
            }
        }

        // …и висящие разделители на конце.
        return trim(rtrim($value, " \t,;:.-–—/\\+&"));
    }
}
