<?php

namespace App\Services\Marketing;

use App\Models\MediaPublication;
use App\Models\MediaTopic;
use Illuminate\Support\Facades\DB;

/**
 * Факты для регулярных публикаций — из наших же данных.
 *
 * Смысл в том, чтобы модель ничего не придумывала: она получает готовый список
 * позиций или посчитанную статистику и только излагает их. Поэтому здесь SQL,
 * а не рассуждения, и поэтому пустой ответ — нормальный ответ: если за неделю
 * нечего показать, публикацию не делаем.
 */
class MediaDataService
{
    /** Сколько позиций отдаём в один материал: длинные списки никто не читает. */
    public const MAX_ITEMS = 20;

    /** Окно по умолчанию, если у темы не задана регулярность. */
    public const DEFAULT_WINDOW_DAYS = 7;

    /** Ниже этого числа уточнений по категории инструкция не окупается. */
    public const MIN_CATEGORY_ITEMS = 25;

    /** @var list<string> */
    public const DATA_SOURCES = ['catalog_new', 'catalog_price', 'stock_arrivals', 'request_tips'];

    public function isDataDriven(MediaTopic $topic): bool
    {
        return in_array($topic->source, self::DATA_SOURCES, true);
    }

    /** Человеку — почему по теме может не быть данных. */
    public function sourceNote(string $source): string
    {
        return match ($source) {
            'catalog_new' => 'Берём позиции, появившиеся в каталоге за окно темы.',
            'catalog_price' => 'Берём позиции, у которых цена за окно темы снизилась.',
            'stock_arrivals' => 'Журнала поступлений в системе нет: пока показываем позиции, вставшие в наличие вместе с последним импортом.',
            'request_tips' => 'Серия: каждый выпуск — инструкция по одной категории товара, о которой ещё не рассказывали. '
                .'Что именно советовать, видно из того, чего нам не хватало в заявках по этой категории.',
            default => 'Материал пишется по брифу темы.',
        };
    }

    public function factsFor(MediaTopic $topic): string
    {
        return $this->factsWithKey($topic)['facts'];
    }

    /**
     * Факты и — для серийных тем — чему посвящён этот выпуск.
     *
     * «Советы по оформлению заявок» — не один пост со сводной статистикой, а
     * серия коротких инструкций: в каждом выпуске одна категория товара, ещё не
     * разобранная. Ключ выпуска возвращаем наружу, чтобы следующий раз взять
     * следующую категорию, а не ту же самую.
     *
     * @return array{key: ?string, facts: string}
     */
    public function factsWithKey(MediaTopic $topic): array
    {
        $days = max(1, (int) ($topic->cadence_days ?: self::DEFAULT_WINDOW_DAYS));

        return match ($topic->source) {
            'catalog_new' => ['key' => null, 'facts' => $this->newItems($days)],
            'catalog_price' => ['key' => null, 'facts' => $this->priceDrops($days)],
            'stock_arrivals' => ['key' => null, 'facts' => $this->arrivals($days)],
            'request_tips' => $this->tipsForNextCategory($topic),
            default => ['key' => null, 'facts' => ''],
        };
    }

    /**
     * Столько же картинок, сколько позиций в подборке (см. жанры в
     * WriteMaterialPrompt): альбом из десяти фотографий под текстом о шести
     * позициях выглядит как чужая нарезка. Порядок совпадает — и данные, и
     * фото идут по убыванию выгоды.
     */
    public const MAX_PHOTOS = 6;

    /**
     * Фотографии позиций для ассортиментного поста.
     *
     * Список деталей без картинок читается как прайс-лист: «поручень резиновый
     * Schindler SDS» ничего не говорит человеку, который ищет деталь глазами.
     * Берём фото ровно тех позиций, о которых пост, в том же порядке.
     *
     * @return list<string>
     */
    public function photosFor(MediaTopic $topic): array
    {
        $days = max(1, (int) ($topic->cadence_days ?: self::DEFAULT_WINDOW_DAYS));

        $rows = match ($topic->source) {
            'catalog_new' => DB::table('catalog_items')
                ->where('is_active', true)
                ->where('created_at', '>=', now()->subDays($days))
                ->orderByDesc('created_at'),
            'catalog_price' => DB::table('catalog_items as ci')
                ->join('catalog_price_changes as pc', 'pc.catalog_item_id', '=', 'ci.id')
                ->where('pc.created_at', '>=', now()->subDays($days))
                ->whereColumn('pc.new_price', '<', 'pc.old_price')
                ->where('ci.is_active', true)
                ->orderByRaw('(pc.old_price - pc.new_price) / NULLIF(pc.old_price, 0) DESC')
                ->select('ci.photo_url'),
            'stock_arrivals' => DB::table('catalog_items')
                ->where('is_active', true)
                ->where('stock_available', '>', 0)
                ->where('last_imported_at', '>=', now()->subDays($days))
                ->where('created_at', '<', now()->subDays($days))
                ->orderByDesc('stock_available'),
            default => null,
        };

        if ($rows === null) {
            return [];
        }

        return $rows
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->limit(self::MAX_ITEMS)
            ->pluck('photo_url')
            ->unique()
            ->take(self::MAX_PHOTOS)
            ->values()
            ->all();
    }

    /**
     * Короткое имя позиции для ленты.
     *
     * Каталожное название несёт всю техническую хвостовую часть («DIN 3062
     * (EN 12385) грузолюдской 8x19S-FC 1570(1370/1770) Н/мм2 44,6 кН»), и в
     * посте она превращается в нечитаемую строку. Обрезаем по границе слова:
     * опознать позицию всё равно позволяет артикул рядом.
     */
    private function short(?string $name, int $limit = 60): string
    {
        $name = trim((string) $name);
        if (mb_strlen($name) <= $limit) {
            return $name;
        }

        $cut = mb_substr($name, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $limit / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;(').'…';
    }

    /** Новые позиции каталога за окно. Цены не даём: их место — в карточке товара. */
    private function newItems(int $days): string
    {
        $rows = DB::table('catalog_items')
            ->where('is_active', true)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS)
            ->get(['sku', 'name', 'brand', 'part_type', 'stock_available']);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Новых позиций в каталоге за '.$days.' дн.: '.$rows->count().'.'];
        foreach ($rows as $r) {
            $lines[] = '— '.$this->short($r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .((float) $r->stock_available > 0 ? ' · есть на складе' : ' · под заказ');
        }

        return implode("\n", $lines);
    }

    /** Позиции, у которых цена снизилась. Показываем обе цены — в этом суть темы. */
    private function priceDrops(int $days): string
    {
        $rows = DB::table('catalog_price_changes as pc')
            ->join('catalog_items as ci', 'ci.id', '=', 'pc.catalog_item_id')
            ->where('pc.created_at', '>=', now()->subDays($days))
            ->whereNotNull('pc.old_price')
            ->whereNotNull('pc.new_price')
            ->whereColumn('pc.new_price', '<', 'pc.old_price')
            ->where('ci.is_active', true)
            ->orderByRaw('(pc.old_price - pc.new_price) / NULLIF(pc.old_price, 0) DESC')
            ->limit(self::MAX_ITEMS)
            ->get(['ci.name', 'ci.brand', 'pc.sku', 'pc.old_price', 'pc.new_price', 'ci.stock_available']);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Позиций со снизившейся ценой за '.$days.' дн.: '.$rows->count().'.'];
        foreach ($rows as $r) {
            $pct = (float) $r->old_price > 0
                ? round(((float) $r->old_price - (float) $r->new_price) * 100 / (float) $r->old_price)
                : 0;
            $lines[] = '— '.$this->short($r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .' · было '.number_format((float) $r->old_price, 0, ',', ' ')
                .' ₽, стало '.number_format((float) $r->new_price, 0, ',', ' ').' ₽'
                .($pct > 0 ? ' (−'.$pct.'%)' : '')
                .((float) $r->stock_available > 0 ? ' · есть на складе' : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Поступления. Журнала остатков нет, поэтому берём то, что можно утверждать
     * честно: позиции с ненулевым остатком, тронутые последним импортом.
     */
    private function arrivals(int $days): string
    {
        $rows = DB::table('catalog_items')
            ->where('is_active', true)
            ->where('stock_available', '>', 0)
            ->where('last_imported_at', '>=', now()->subDays($days))
            ->where('created_at', '<', now()->subDays($days))
            ->orderByDesc('stock_available')
            ->limit(self::MAX_ITEMS)
            ->get(['sku', 'name', 'brand', 'stock_available']);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Позиции в наличии по последнему обновлению склада:'];
        foreach ($rows as $r) {
            $lines[] = '— '.$this->short($r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .' · на складе '.rtrim(rtrim(number_format((float) $r->stock_available, 2, ',', ' '), '0'), ',');
        }

        return implode("\n", $lines);
    }

    /**
     * Инструкция по одной категории товара: чему посвятить следующий выпуск и
     * что о ней известно.
     *
     * Серия устроена так: берём категорию, по которой мы чаще всего пишем
     * уточнения и о которой ещё не рассказывали. Если рассказали обо всех —
     * возвращаемся к той, что разбирали дольше всех: за квартал состав заявок
     * успевает поменяться.
     *
     * Модель получает три вещи: чего не хватало именно в этой категории,
     * как клиенты формулируют такие позиции у себя в заявках и как те же
     * позиции называются в нашем каталоге. Последнее и есть источник
     * конкретики: по каталожным названиям видно, чем позиции различаются
     * между собой — серия, символ, подсветка, разъём, размер, — а значит,
     * что именно клиенту нужно указать, чтобы выбор был однозначным.
     *
     * @return array{key: ?string, facts: string}
     */
    private function tipsForNextCategory(MediaTopic $topic): array
    {
        $since = now()->subDays(90);

        $requestIds = DB::table('request_state_changes')
            ->where('to_status', 'awaiting_client_clarification')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('request_id');

        if ($requestIds->isEmpty()) {
            return ['key' => null, 'facts' => ''];
        }

        $categories = DB::table('request_items')
            ->whereIn('request_id', $requestIds)
            ->where('is_active', true)
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) AS c')
            ->groupBy('category')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_CATEGORY_ITEMS])
            ->orderByDesc('c')
            ->pluck('c', 'category');

        if ($categories->isEmpty()) {
            return ['key' => null, 'facts' => ''];
        }

        $category = $this->nextCategory($topic, $categories->keys()->all());
        if ($category === null) {
            return ['key' => null, 'facts' => ''];
        }

        $items = DB::table('request_items')
            ->whereIn('request_id', $requestIds)
            ->where('is_active', true)
            ->where('category', $category);

        $stats = (clone $items)->selectRaw(
            "COUNT(*) AS total,
             COUNT(*) FILTER (WHERE parsed_article IS NULL OR btrim(parsed_article) = '') AS no_article,
             COUNT(*) FILTER (WHERE parsed_brand IS NULL OR btrim(parsed_brand) = '') AS no_brand,
             COUNT(*) FILTER (WHERE parsed_qty IS NULL OR parsed_qty <= 0) AS no_qty,
             COUNT(*) FILTER (WHERE image_attachment_id IS NULL) AS no_photo,
             COUNT(*) FILTER (WHERE catalog_item_id IS NULL) AS no_match"
        )->first();

        $total = (int) ($stats->total ?? 0);
        if ($total === 0) {
            return ['key' => null, 'facts' => ''];
        }
        $pct = fn ($n) => (int) round((int) $n * 100 / $total);

        // Как клиенты пишут такие позиции у себя — короткие строки без артикула
        // показательнее всего: именно из-за них и начинается переписка.
        $asked = (clone $items)
            ->whereNotNull('parsed_name')
            ->orderByRaw('length(parsed_name)')
            ->limit(12)
            ->pluck('parsed_name')
            ->map(fn ($n) => $this->short($n, 80))
            ->unique()
            ->values();

        // Чем позиции категории различаются в каталоге — по этим названиям
        // видно, какие признаки делают выбор однозначным.
        $catalog = DB::table('request_items as ri')
            ->join('catalog_items as ci', 'ci.id', '=', 'ri.catalog_item_id')
            ->whereIn('ri.request_id', $requestIds)
            ->where('ri.category', $category)
            ->whereNotNull('ri.catalog_item_id')
            ->distinct()
            ->limit(12)
            ->pluck('ci.name')
            ->map(fn ($n) => $this->short($n, 90))
            ->unique()
            ->values();

        $lines = [
            'ТЕМА ВЫПУСКА: как оформить заявку на категорию «'.$category.'».',
            '',
            'За 90 дней по этой категории нам пришлось уточнять '.$total.' позиций. Не хватало:',
            '— артикула: '.$pct($stats->no_article).'% позиций',
            '— бренда или производителя: '.$pct($stats->no_brand).'% позиций',
            '— фотографии: '.$pct($stats->no_photo).'% позиций',
            '— количества: '.$pct($stats->no_qty).'% позиций',
            '— по '.$pct($stats->no_match).'% позиций подбор по каталогу не сошёлся сразу.',
        ];

        if ($asked->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Так эти позиции выглядят в заявках клиентов (по ним и приходится спрашивать):';
            foreach ($asked as $name) {
                $lines[] = '— '.$name;
            }
        }

        if ($catalog->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Так они называются в нашем каталоге — по этим названиям видно, '
                .'какими признаками позиции отличаются друг от друга:';
            foreach ($catalog as $name) {
                $lines[] = '— '.$name;
            }
        }

        return ['key' => $category, 'facts' => implode("\n", $lines)];
    }

    /**
     * Следующая категория серии: первая неразобранная, иначе разобранная
     * раньше всех.
     *
     * @param  list<string>  $ordered  категории по убыванию числа уточнений
     */
    private function nextCategory(MediaTopic $topic, array $ordered): ?string
    {
        $covered = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('subject_key')
            ->orderByDesc('id')
            ->pluck('subject_key')
            ->all();

        foreach ($ordered as $category) {
            if (! in_array($category, $covered, true)) {
                return $category;
            }
        }

        // Все разобраны — берём ту, что разбирали дольше всех.
        $oldest = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('subject_key')
            ->whereIn('subject_key', $ordered)
            ->orderBy('id')
            ->value('subject_key');

        return $oldest ?: ($ordered[0] ?? null);
    }
}
