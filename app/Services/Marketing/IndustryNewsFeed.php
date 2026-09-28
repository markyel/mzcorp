<?php

namespace App\Services\Marketing;

use Illuminate\Support\Carbon;
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

    /** Больше в один дайджест не берём: модель всё равно отберёт 5–7. */
    public const MAX_ITEMS = 25;

    /**
     * Новости за последние $days дней, свежие первыми.
     *
     * @return array{home: ?string, items: list<array{title: string, description: string, link: string, published_at: Carbon}>}
     */
    public function recent(int $days): array
    {
        $url = (string) config('services.marketing.news_digest_feed');
        if ($url === '') {
            return ['home' => null, 'items' => []];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }
            $feed = $this->parse($response->body());
        } catch (\Throwable $e) {
            Log::warning('IndustryNewsFeed: лента не прочиталась', ['url' => $url, 'error' => $e->getMessage()]);

            return ['home' => null, 'items' => []];
        }

        $since = now()->subDays(max(1, $days));
        $items = array_values(array_filter(
            $feed['items'],
            fn (array $i) => $i['published_at']->gte($since),
        ));
        usort($items, fn (array $a, array $b) => $b['published_at'] <=> $a['published_at']);

        return ['home' => $feed['home'], 'items' => array_slice($items, 0, self::MAX_ITEMS)];
    }

    /**
     * @return array{home: ?string, items: list<array{title: string, description: string, link: string, published_at: Carbon}>}
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

            $items[] = [
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
