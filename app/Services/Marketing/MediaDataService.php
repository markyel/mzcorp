<?php

namespace App\Services\Marketing;

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
            'request_tips' => 'Считаем, чего чаще всего не хватало в заявках, по которым пришлось писать клиенту уточнение.',
            default => 'Материал пишется по брифу темы.',
        };
    }

    public function factsFor(MediaTopic $topic): string
    {
        $days = max(1, (int) ($topic->cadence_days ?: self::DEFAULT_WINDOW_DAYS));

        return match ($topic->source) {
            'catalog_new' => $this->newItems($days),
            'catalog_price' => $this->priceDrops($days),
            'stock_arrivals' => $this->arrivals($days),
            'request_tips' => $this->requestTips(),
            default => '',
        };
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
            $lines[] = '— '.trim((string) $r->name)
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
            $lines[] = '— '.trim((string) $r->name)
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
            $lines[] = '— '.trim((string) $r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .' · на складе '.rtrim(rtrim(number_format((float) $r->stock_available, 2, ',', ' '), '0'), ',');
        }

        return implode("\n", $lines);
    }

    /**
     * Из-за чего мы пишем клиентам уточнения.
     *
     * Считаем по заявкам, которые за квартал уходили в «жду клиента»: чего
     * не хватало в их позициях. Это и есть материал для советов — не общие
     * слова «пишите подробнее», а конкретика по категориям деталей.
     */
    private function requestTips(): string
    {
        $since = now()->subDays(90);

        $ids = DB::table('request_state_changes')
            ->where('to_status', 'awaiting_client_clarification')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('request_id');

        if ($ids->isEmpty()) {
            return '';
        }

        $stats = DB::table('request_items')
            ->whereIn('request_id', $ids)
            ->where('is_active', true)
            ->selectRaw(
                "COUNT(*) AS total,
                 COUNT(*) FILTER (WHERE parsed_article IS NULL OR btrim(parsed_article) = '') AS no_article,
                 COUNT(*) FILTER (WHERE parsed_brand IS NULL OR btrim(parsed_brand) = '') AS no_brand,
                 COUNT(*) FILTER (WHERE parsed_qty IS NULL OR parsed_qty <= 0) AS no_qty,
                 COUNT(*) FILTER (WHERE image_attachment_id IS NULL) AS no_photo,
                 COUNT(*) FILTER (WHERE catalog_item_id IS NULL) AS no_match"
            )
            ->first();

        if ($stats === null || (int) $stats->total === 0) {
            return '';
        }

        $pct = fn ($n) => round((int) $n * 100 / (int) $stats->total);

        $top = DB::table('request_items')
            ->whereIn('request_id', $ids)
            ->where('is_active', true)
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) AS c')
            ->groupBy('category')
            ->orderByDesc('c')
            ->limit(8)
            ->get();

        $lines = [
            'За 90 дней нам пришлось писать уточнение по '.$ids->count().' заявкам.',
            'В этих заявках '.(int) $stats->total.' позиций, и в них не хватало:',
            '— артикула: '.$pct($stats->no_article).'% позиций',
            '— бренда или производителя: '.$pct($stats->no_brand).'% позиций',
            '— количества: '.$pct($stats->no_qty).'% позиций',
            '— фотографии детали: '.$pct($stats->no_photo).'% позиций',
            '— подбор по каталогу не сошёлся сразу: '.$pct($stats->no_match).'% позиций',
        ];

        if ($top->isNotEmpty()) {
            $lines[] = 'Чаще всего уточняли по категориям: '
                .$top->map(fn ($r) => trim((string) $r->category).' ('.$r->c.')')->implode(', ').'.';
        }

        return implode("\n", $lines);
    }
}
