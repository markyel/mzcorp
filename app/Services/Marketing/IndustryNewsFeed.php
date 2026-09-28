<?php

namespace App\Services\Marketing;

use App\Models\IndustryNewsItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Лента новостей отрасли (RSS) — сырьё для еженедельного дайджеста.
 *
 * Новости не наши: мы их отбираем и пересказываем, поэтому в дайджест идёт
 * только то, что есть в заголовке и анонсе ленты, и ссылка на первоисточник.
 * Лента не ответила — дайджеста в этот раз нет, выдумывать не из чего.
 */
class IndustryNewsFeed
{
    private const TIMEOUT = 15;

    /** Потолок новостей в один обзор — чтобы промпт не распух, если лента разрастётся. */
    public const MAX_ITEMS = 50;

    private const HOME_CACHE_KEY = 'media:news-feed-home';

    /**
     * Забрать ленту и сложить новые новости в industry_news_items.
     *
     * Лента держит только 30 последних новостей (4–5 дней), поэтому читаем
     * её несколько раз в сутки (media:news-fetch), а дайджест собираем из
     * накопленного.
     *
     * @return int|null сколько новых; null — лента не прочиталась
     */
    public function sync(): ?int
    {
        $url = (string) config('services.marketing.news_digest_feed');
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }
            $feed = $this->parse($response->body());
        } catch (\Throwable $e) {
            Log::warning('IndustryNewsFeed: лента не прочиталась', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($feed['home'] !== null) {
            Cache::forever(self::HOME_CACHE_KEY, $feed['home']);
        }

        $new = 0;
        foreach ($feed['items'] as $item) {
            $row = IndustryNewsItem::firstOrCreate(['guid' => mb_substr($item['guid'], 0, 500)], [
                'title' => $item['title'],
                'description' => $item['description'] !== '' ? $item['description'] : null,
                'link' => $item['link'],
                'published_at' => $item['published_at'],
                'feed_url' => mb_substr($url, 0, 500),
            ]);
            $new += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $new;
    }

    /**
     * Ровная неделя перед днём выпуска: $days полных суток, заканчивая
     * вчерашним днём. Выпуск в пятницу 02.10 → с 25.09 по 01.10; в
     * понедельник — ровно прошлая календарная неделя.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(Carbon $releaseDay, int $days): array
    {
        $to = $releaseDay->copy()->startOfDay()->subSecond();
        $from = $releaseDay->copy()->startOfDay()->subDays(max(1, $days));

        return [$from, $to];
    }

    /**
     * Новости за окно, по порядку публикации. Перед выборкой — свежее чтение
     * ленты: если расписание пропустило запуск, хотя бы последние дни будут.
     *
     * @return array{home: ?string, items: list<array{title: string, description: string, link: string, published_at: Carbon}>}
     */
    public function between(Carbon $from, Carbon $to): array
    {
        $this->sync();

        $items = IndustryNewsItem::query()
            ->whereBetween('published_at', [$from, $to])
            ->orderBy('published_at')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (IndustryNewsItem $n) => [
                'title' => (string) $n->title,
                'description' => (string) $n->description,
                'link' => (string) $n->link,
                'published_at' => $n->published_at->copy()->setTimezone(config('app.timezone')),
            ])
            ->all();

        $home = Cache::get(self::HOME_CACHE_KEY);

        return ['home' => is_string($home) ? $home : null, 'items' => $items];
    }

    /**
     * @return array{home: ?string, items: list<array{guid: string, title: string, description: string, link: string, published_at: Carbon}>}
     */
    public function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($prev);

        if ($doc === false || ! isset($doc->channel)) {
            throw new \RuntimeException('не RSS');
        }

        $items = [];
        foreach ($doc->channel->item as $item) {
            $title = $this->clean((string) $item->title);
            $link = trim((string) $item->link);
            if ($title === '' || ! preg_match('~^https?://~i', $link)) {
                continue;
            }

            try {
                $at = Carbon::parse((string) $item->pubDate)->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                continue;
            }

            $guid = trim((string) $item->guid);
            $items[] = [
                'guid' => $guid !== '' ? $guid : $link,
                'title' => $title,
                'description' => $this->clean((string) $item->description),
                'link' => $link,
                'published_at' => $at,
            ];
        }

        $home = trim((string) $doc->channel->link);

        return ['home' => preg_match('~^https?://~i', $home) ? $home : null, 'items' => $items];
    }

    /** Анонсы бывают с HTML и сущностями — модели нужен чистый текст. */
    private function clean(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }
}
