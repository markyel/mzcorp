<?php

namespace App\Services\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaProfileEntry;
use App\Models\MediaPublication;
use App\Models\MediaTopic;
use App\Models\User;
use App\Prompts\Marketing\WriteWeeklyRoundupPrompt;
use App\Services\AI\OpenAIChatService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Обзор недели для RSS-ленты и страницы /news: новинки ассортимента,
 * снижение цен и поступления на склад одной статьёй.
 *
 * Как устроено, чтобы статья была и интересной, и правдивой:
 *   — кандидатов в каждый раздел отбираем мы: с фото, с понятным брендом,
 *     без мелочи, без позиций из прошлых выпусков;
 *   — модель выбирает из кандидатов и пишет только слова (заголовок,
 *     вступление, по фразе о позиции);
 *   — цены, остатки, фото и ссылки подставляем из каталога при сборке.
 * Позицию с артикулом не из кандидатов выбрасываем, раздел без позиций — тоже.
 */
class WeeklyRoundupService
{
    /** Кандидатов в раздел: модели есть из чего выбрать, а прайс не получается. */
    private const CANDIDATES = 8;

    /** Позиций в разделе статьи. */
    public const MAX_ITEMS = 4;

    /** Снижение меньше — не новость: копейки на шайбе редакции не интересны. */
    private const MIN_DROP_PCT = 5;

    private const MIN_DROP_RUB = 300;

    /** Сколько прошлых выпусков смотреть, чтобы позиции не повторялись. */
    private const REPEAT_WEEKS = 8;

    public const SECTIONS = ['new', 'price', 'stock'];

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly MediaLinkService $links,
    ) {}

    /**
     * Кандидаты по разделам и итоги недели.
     *
     * @return array{from: Carbon, to: Carbon, totals: array<string, int>, stock_proxy: bool,
     *     sections: array<string, list<array<string, mixed>>>}
     */
    public function candidates(int $days = 7): array
    {
        $to = now();
        $from = now()->subDays($days);
        $featured = $this->recentlyFeatured();

        $base = fn () => DB::table('catalog_items as ci')
            ->where('ci.is_active', true)
            ->whereNotNull('ci.photo_url')->where('ci.photo_url', '!=', '')
            ->whereNotIn('ci.sku', $featured ?: [''])
            // «(ЗАМЕНЕНО НА M…)» — снятая позиция, в новостях ей не место.
            ->where('ci.name', 'not ilike', '%замен%на m%');
        $cols = ['ci.sku', 'ci.name', 'ci.brand', 'ci.part_type', 'ci.photo_url', 'ci.price', 'ci.stock_available'];

        // Новинки: заведены за неделю. Сначала то, что уже на складе и с брендом.
        $new = $base()
            ->where('ci.created_at', '>=', $from)
            ->orderByRaw('CASE WHEN ci.stock_available > 0 THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN coalesce(ci.brand, '') <> '' THEN 0 ELSE 1 END")
            ->orderByDesc('ci.price')
            ->limit(self::CANDIDATES)->get($cols);

        // Снижение цен: заметное в процентах и в рублях, по убыванию процента.
        $price = $base()
            ->join('catalog_price_changes as pc', 'pc.catalog_item_id', '=', 'ci.id')
            ->where('pc.created_at', '>=', $from)
            ->whereNotNull('pc.old_price')->whereNotNull('pc.new_price')
            ->whereColumn('pc.new_price', '<', 'pc.old_price')
            ->whereRaw('(pc.old_price - pc.new_price) >= ?', [self::MIN_DROP_RUB])
            ->whereRaw('(pc.old_price - pc.new_price) * 100 >= pc.old_price * ?', [self::MIN_DROP_PCT])
            ->orderByRaw('(pc.old_price - pc.new_price) / NULLIF(pc.old_price, 0) DESC')
            ->limit(self::CANDIDATES * 2)
            ->get(array_merge($cols, ['pc.old_price', 'pc.new_price']))
            ->unique('sku')->take(self::CANDIDATES)->values();

        // Поступления: остаток появился за неделю (метка импорта). Пока меток
        // мало — честно показываем «сейчас в наличии» из последнего импорта.
        $stockRows = $base()
            ->where('ci.stock_available', '>', 0)
            ->where('ci.in_stock_since', '>=', $from)
            ->where('ci.created_at', '<', $from)
            ->orderByDesc('ci.price')
            ->limit(self::CANDIDATES)->get($cols);
        $stockProxy = $stockRows->count() < 2;
        if ($stockProxy) {
            $stockRows = $base()
                ->where('ci.stock_available', '>', 0)
                ->where('ci.last_imported_at', '>=', $from)
                ->where('ci.created_at', '<', $from)
                ->whereRaw("coalesce(ci.brand, '') <> ''")
                ->orderByDesc('ci.price')
                ->limit(self::CANDIDATES)->get($cols);
        }

        $totals = [
            'new' => (int) DB::table('catalog_items')->where('is_active', true)->where('created_at', '>=', $from)->count(),
            'price' => (int) DB::table('catalog_price_changes')->where('created_at', '>=', $from)
                ->whereColumn('new_price', '<', 'old_price')->distinct()->count('catalog_item_id'),
            'stock' => (int) DB::table('catalog_items')->where('is_active', true)->where('stock_available', '>', 0)
                ->where('in_stock_since', '>=', $from)->count(),
        ];

        return [
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'stock_proxy' => $stockProxy,
            'sections' => [
                'new' => $new->map(fn ($r) => $this->item($r))->all(),
                'price' => $price->map(fn ($r) => $this->item($r))->all(),
                'stock' => $stockRows->map(fn ($r) => $this->item($r))->all(),
            ],
        ];
    }

    /** Факты для модели: итоги и пронумерованные кандидаты по разделам. */
    public function factsText(array $c): string
    {
        $period = MediaDataService::periodLabel($c['from'], $c['to']);
        $lines = [
            'ПЕРИОД: '.$period.'.',
            'ИТОГИ НЕДЕЛИ: новых позиций в каталоге — '.$c['totals']['new']
                .'; позиций со сниженной ценой — '.$c['totals']['price']
                .($c['stock_proxy'] ? '' : '; позиций, поступивших на склад, — '.$c['totals']['stock']).'.',
        ];
        $titles = [
            'new' => 'КАНДИДАТЫ — НОВИНКИ АССОРТИМЕНТА (kind=new)',
            'price' => 'КАНДИДАТЫ — СНИЖЕНИЕ ЦЕН (kind=price)',
            'stock' => $c['stock_proxy']
                ? 'КАНДИДАТЫ — СЕЙЧАС В НАЛИЧИИ НА СКЛАДЕ (kind=stock; это НЕ поступления недели — так и пиши: «в наличии»)'
                : 'КАНДИДАТЫ — ПОСТУПИЛИ НА СКЛАД ЗА НЕДЕЛЮ (kind=stock)',
        ];
        foreach (self::SECTIONS as $kind) {
            if ($c['sections'][$kind] === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = $titles[$kind].':';
            foreach ($c['sections'][$kind] as $it) {
                $lines[] = '— sku '.$it['sku'].' · '.$it['name']
                    .($it['brand'] ? ' · бренд '.$it['brand'] : '')
                    .($it['part_type'] ? ' · тип '.$it['part_type'] : '')
                    .($kind === 'price' ? ' · цена снижена на '.$it['pct'].'%' : '')
                    .($it['in_stock'] ? ' · есть на складе' : ' · под заказ');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Черновик обзора в канал. Та же форма ответа, что у MediaMaterialService::draft.
     *
     * @return array{ok: bool, publication: ?MediaPublication, message: string}
     */
    public function draft(MediaTopic $topic, MediaChannel $channel, ?User $author): array
    {
        $c = $this->candidates(max(1, (int) ($topic->cadence_days ?: 7)));
        $filled = array_filter($c['sections'], fn ($s) => count($s) > 0);
        if (count($filled) < 2) {
            return ['ok' => false, 'publication' => null, 'message' => 'За неделю мало изменений для обзора: меньше двух разделов с позициями.'];
        }

        $profile = MediaProfileEntry::asBrief();
        $model = (string) config('services.openai.media_profile_model', 'gpt-4o');
        try {
            $response = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => WriteWeeklyRoundupPrompt::systemMessage()],
                    ['role' => 'user', 'content' => WriteWeeklyRoundupPrompt::userMessage($profile, $this->factsText($c))],
                ],
                $model,
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0.5],
            );
        } catch (\Throwable $e) {
            Log::error('WeeklyRoundupService: модель не ответила', ['topic_id' => $topic->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'publication' => null, 'message' => 'Не удалось написать обзор: '.$e->getMessage()];
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        $article = is_array($parsed) ? $this->assemble($parsed, $c) : null;
        if ($article === null) {
            return ['ok' => false, 'publication' => null, 'message' => 'Модель ответила не по форме или выбрала позиции не из данных — попробуйте ещё раз.'];
        }

        $publication = MediaPublication::create([
            'media_topic_id' => $topic->id,
            'media_channel_id' => $channel->id,
            'title' => $article['title'],
            'body' => self::toText($article),
            'article' => $article,
            'subject_key' => 'roundup:'.$c['from']->toDateString().'..'.$c['to']->toDateString(),
            'image_urls' => array_values(array_unique(array_filter(array_merge(
                ...array_map(fn ($s) => array_column($s['items'], 'photo'), $article['sections']),
            )))) ?: null,
            'status' => 'draft',
            'planned_for' => $topic->next_due_on ?? now()->toDateString(),
            'model' => $model,
            'created_by_user_id' => $author?->id,
        ]);

        $notes = trim((string) ($parsed['notes'] ?? ''));

        return [
            'ok' => true,
            'publication' => $publication,
            'message' => 'Черновик обзора готов.'.($notes !== '' ? ' Редактору: '.$notes : ''),
        ];
    }

    /**
     * Ответ модели + данные каталога → статья. Позиции не из кандидатов
     * раздела отбрасываем; раздел без позиций не выходит; меньше двух
     * разделов — обзора нет.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    public function assemble(array $parsed, array $c): ?array
    {
        $title = trim((string) ($parsed['title'] ?? ''));
        $lead = trim((string) ($parsed['lead'] ?? ''));
        if ($title === '' || $lead === '') {
            return null;
        }

        $sections = [];
        foreach ((array) ($parsed['sections'] ?? []) as $s) {
            $kind = (string) ($s['kind'] ?? '');
            if (! in_array($kind, self::SECTIONS, true) || isset($sections[$kind])) {
                continue;
            }
            $pool = collect($c['sections'][$kind] ?? [])->keyBy('sku');
            $items = [];
            foreach ((array) ($s['items'] ?? []) as $it) {
                $sku = strtoupper(trim((string) ($it['sku'] ?? '')));
                $cand = $pool->get($sku);
                if ($cand === null || isset($items[$sku]) || count($items) >= self::MAX_ITEMS) {
                    continue;
                }
                $items[$sku] = $cand + ['note' => trim((string) ($it['note'] ?? ''))];
            }
            if ($items === []) {
                continue;
            }
            $sections[$kind] = [
                'kind' => $kind,
                'heading' => trim((string) ($s['heading'] ?? '')) ?: self::defaultHeading($kind, (bool) $c['stock_proxy']),
                'intro' => trim((string) ($s['intro'] ?? '')),
                'items' => array_values($items),
            ];
        }
        if (count($sections) < 2) {
            return null;
        }

        // Порядок разделов фиксированный: новинки, цены, склад.
        $ordered = array_values(array_filter(array_map(fn ($k) => $sections[$k] ?? null, self::SECTIONS)));

        return [
            'title' => mb_substr($title, 0, 160),
            'lead' => $lead,
            'sections' => $ordered,
            'closing' => trim((string) ($parsed['closing'] ?? '')),
            'period' => MediaDataService::periodLabel($c['from'], $c['to']),
            'stock_proxy' => (bool) $c['stock_proxy'],
            'cover' => $ordered[0]['items'][0]['photo'] ?? null,
        ];
    }

    public static function defaultHeading(string $kind, bool $stockProxy = false): string
    {
        return match ($kind) {
            'new' => 'Новое в ассортименте',
            'price' => 'Снижение цен',
            default => $stockProxy ? 'Сейчас в наличии' : 'Поступило на склад',
        };
    }

    /** Текстовая версия — для редактора в разделе «Медиаплан». */
    public static function toText(array $article): string
    {
        $out = [$article['lead'], ''];
        foreach ($article['sections'] as $s) {
            $out[] = $s['heading'];
            if ($s['intro'] !== '') {
                $out[] = $s['intro'];
            }
            foreach ($s['items'] as $it) {
                $out[] = '• '.$it['name'].' · арт. '.$it['sku'].' — '.self::priceLine($it, $s['kind'])
                    .($it['note'] !== '' ? "\n  ".$it['note'] : '');
            }
            $out[] = '';
        }
        if ($article['closing'] !== '') {
            $out[] = $article['closing'];
        }

        return trim(implode("\n", $out));
    }

    /**
     * HTML статьи для страницы и RSS: только p, img, a, strong, br — этот набор
     * принимают и Дзен, и ленты порталов (у Дзена другие теги запрещены).
     */
    public static function toHtml(array $article): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<p>'.$e($article['lead']).'</p>';
        foreach ($article['sections'] as $s) {
            $html .= '<p><strong>'.$e($s['heading']).'</strong></p>';
            if ($s['intro'] !== '') {
                $html .= '<p>'.$e($s['intro']).'</p>';
            }
            foreach ($s['items'] as $it) {
                if (! empty($it['photo'])) {
                    $html .= '<p><img src="'.$e($it['photo']).'" alt="'.$e($it['name']).'"></p>';
                }
                $html .= '<p><a href="'.$e($it['url']).'"><strong>'.$e($it['name']).'</strong></a>'
                    .' · арт. '.$e($it['sku']).'<br>'.$e(self::priceLine($it, $s['kind']))
                    .($it['note'] !== '' ? '<br>'.$e($it['note']) : '').'</p>';
            }
        }
        if ($article['closing'] !== '') {
            $html .= '<p>'.$e($article['closing']).'</p>';
        }

        return $html;
    }

    /**
     * Цена и наличие строкой — из каталога, не из текста модели. Цену даём
     * только в разделе снижения, как рубрика «Снижение цен» в соцсетях:
     * розничные цены на сайте анонимно не показываются, у новинок и
     * поступлений их место — в карточке товара.
     */
    public static function priceLine(array $it, string $kind): string
    {
        $stock = $it['in_stock'] ? 'есть на складе' : 'под заказ';
        $drop = $kind === 'price' ? self::priceDrop($it) : null;

        return $drop !== null ? $drop.', '.$stock : $stock;
    }

    /** «8 461 ₽ → 1 196 ₽ (−86%)»; null — у позиции нет снижения. */
    public static function priceDrop(array $it): ?string
    {
        if (($it['old_price'] ?? null) === null || ($it['new_price'] ?? null) === null) {
            return null;
        }
        $rub = fn ($v) => number_format((float) $v, 0, ',', "\u{00A0}")."\u{00A0}₽";

        return $rub($it['old_price']).' → '.$rub($it['new_price']).' (−'.$it['pct'].'%)';
    }

    /** @return array<string, mixed> */
    private function item(object $r): array
    {
        $old = isset($r->old_price) ? (float) $r->old_price : null;
        $new = isset($r->new_price) ? (float) $r->new_price : null;

        return [
            'sku' => (string) $r->sku,
            'name' => self::shortName((string) $r->name),
            'brand' => trim((string) $r->brand),
            'part_type' => trim((string) $r->part_type),
            'photo' => (string) $r->photo_url,
            'url' => $this->links->productUrl((string) $r->sku, 'rss'),
            'price' => (float) ($new ?? $r->price ?? 0),
            'old_price' => $old,
            'new_price' => $new,
            'pct' => $old && $new !== null && $old > 0 ? (int) round(($old - $new) * 100 / $old) : 0,
            'in_stock' => (float) $r->stock_available > 0,
        ];
    }

    /** Каталожный хвост характеристик в новости не нужен: обрезаем по слову. */
    private static function shortName(string $name, int $limit = 90): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (mb_strlen($name) <= $limit) {
            return $name;
        }
        $cut = mb_substr($name, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $limit / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;(').'…';
    }

    /** Артикулы из обзоров последних недель — чтобы выпуски не повторялись. @return list<string> */
    private function recentlyFeatured(): array
    {
        $skus = [];
        $rows = MediaPublication::query()
            ->whereNotNull('article')
            ->where('status', 'published')
            ->where('created_at', '>=', now()->subWeeks(self::REPEAT_WEEKS))
            ->pluck('article');
        foreach ($rows as $article) {
            foreach ((array) ($article['sections'] ?? []) as $s) {
                foreach ((array) ($s['items'] ?? []) as $it) {
                    $skus[] = (string) ($it['sku'] ?? '');
                }
            }
        }

        return array_values(array_unique(array_filter($skus)));
    }
}
