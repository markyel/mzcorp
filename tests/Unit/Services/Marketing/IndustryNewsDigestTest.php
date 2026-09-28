<?php

namespace Tests\Unit\Services\Marketing;

use App\Services\Marketing\IndustryNewsFeed;
use App\Services\Marketing\MediaDataService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Дайджест новостей отрасли: разбор RSS, окно по дате и подстановка ссылок
 * вместо меток [n]. БД не нужна.
 */
class IndustryNewsDigestTest extends TestCase
{
    private function rss(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
            .'<title>Лифты и эскалаторы — новости</title><link>https://liftpages.ru/news</link>';
        foreach ($items as [$title, $link, $date, $desc]) {
            $xml .= '<item><title>'.$title.'</title><link>'.$link.'</link>'
                .'<pubDate>'.$date.'</pubDate><description><![CDATA['.$desc.']]></description></item>';
        }

        return $xml.'</channel></rss>';
    }

    public function test_parses_items_and_home(): void
    {
        $feed = (new IndustryNewsFeed)->parse($this->rss([
            ['КМЗ установил 2942 лифта', 'https://liftpages.ru/news/kmz', 'Sat, 26 Sep 2026 10:40:01 +0000', '<p>Карачаровский &laquo;завод&raquo;</p>'],
            ['Без ссылки', 'not-a-url', 'Sat, 26 Sep 2026 10:40:01 +0000', ''],
        ]));

        $this->assertSame('https://liftpages.ru/news', $feed['home']);
        $this->assertCount(1, $feed['items']);
        $this->assertSame('Карачаровский «завод»', $feed['items'][0]['description']);
        $this->assertSame('26.09.2026 13:40', $feed['items'][0]['published_at']->format('d.m.Y H:i'));
    }

    public function test_recent_keeps_window_and_sorts_fresh_first(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(9, 15));
        Http::fake(['*' => Http::response($this->rss([
            ['Старая', 'https://x.ru/1', 'Mon, 14 Sep 2026 10:00:00 +0000', ''],
            ['Вторая', 'https://x.ru/2', 'Fri, 25 Sep 2026 10:00:00 +0000', ''],
            ['Свежая', 'https://x.ru/3', 'Sun, 27 Sep 2026 10:00:00 +0000', ''],
        ]))]);

        $items = (new IndustryNewsFeed)->recent(7)['items'];

        $this->assertSame(['Свежая', 'Вторая'], array_column($items, 'title'));
    }

    public function test_feed_failure_gives_no_items(): void
    {
        Http::fake(['*' => Http::response('oops', 500)]);

        $this->assertSame([], (new IndustryNewsFeed)->recent(7)['items']);
    }

    public function test_resolve_links_replaces_known_marks_and_drops_unknown(): void
    {
        $links = [0 => 'https://liftpages.ru/news', 1 => 'https://liftpages.ru/news/a', 2 => 'https://liftpages.ru/news/b'];
        $text = "• КМЗ установил почти 3000 лифтов [1].\n• ХМАО обновит парк [2]\n• Лишнее [9]\nВсе новости отрасли: [0]";

        $this->assertSame(
            "• КМЗ установил почти 3000 лифтов https://liftpages.ru/news/a\n"
            ."• ХМАО обновит парк https://liftpages.ru/news/b\n"
            ."• Лишнее\n"
            .'Все новости отрасли: https://liftpages.ru/news',
            (new MediaDataService)->resolveLinks($text, $links),
        );
    }

    public function test_resolve_links_without_links_leaves_text(): void
    {
        $this->assertSame('Шаг [1] и шаг [2]', (new MediaDataService)->resolveLinks('Шаг [1] и шаг [2]', []));
    }
}
