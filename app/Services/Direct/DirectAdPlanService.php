<?php

namespace App\Services\Direct;

use App\Services\Catalog\YandexDirectFeedService;
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

    public function __construct(private readonly DirectCandidateService $candidates) {}

    /**
     * План по первым $limit позициям очереди.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(int $limit): Collection
    {
        return $this->candidates->queue($limit)->take($limit)->map(fn ($item) => $this->forItem($item));
    }

    /**
     * @return array<string, mixed>
     */
    public function forItem(object $item): array
    {
        $keywords = self::keywords($item);
        $title = self::adTitle($item);
        $text = self::adText($item);

        $warnings = [];
        if ($keywords === []) {
            $warnings[] = 'Нет кодов производителя — рекламировать нечем, фразы пустые.';
        }
        if (mb_strlen((string) ($item->name ?? '')) > self::TITLE_MAX) {
            $warnings[] = 'Название длиннее заголовка — обрезано, проверьте читаемость.';
        }

        return [
            'sku' => (string) ($item->sku ?? ''),
            'name' => (string) ($item->name ?? ''),
            'brand' => (string) ($item->brand ?? ''),
            'group' => self::groupName($item),
            'title' => $title,
            'title2' => self::adTitle2(),
            'text' => $text,
            'url' => YandexDirectFeedService::productUrl((string) ($item->sku ?? '')),
            'keywords' => $keywords,
            'warnings' => $warnings,
            'price' => (float) ($item->price ?? 0),
            'stock' => (int) ($item->stock_available ?? 0),
        ];
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

    public static function adTitle2(): string
    {
        return Str::limit('Со склада, отгрузка сразу', self::TITLE2_MAX, '');
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
            return $value;
        }
        $cut = mb_substr($value, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return trim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut);
    }
}
