<?php

namespace Tests\Unit\Services\Marketing;

use App\Services\Marketing\IndustryNewsFeed;
use App\Services\Marketing\MediaDataService;
use Illuminate\Support\Carbon;
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

    public function test_guid_falls_back_to_link(): void
    {
        $feed = (new IndustryNewsFeed)->parse($this->rss([
            ['Новость', 'https://liftpages.ru/news/a', 'Sat, 26 Sep 2026 10:40:01 +0000', ''],
        ]));

        $this->assertSame('https://liftpages.ru/news/a', $feed['items'][0]['guid']);
    }

    public function test_window_is_whole_days_before_release(): void
    {
        [$from, $to] = IndustryNewsFeed::window(Carbon::parse('2026-10-02 09:15'), 7);
        $this->assertSame('2026-09-25 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 23:59:59', $to->format('Y-m-d H:i:s'));

        // Выпуск в понедельник — ровно прошлая календарная неделя.
        [$from, $to] = IndustryNewsFeed::window(Carbon::parse('2026-09-28 09:15'), 7);
        $this->assertSame('2026-09-21 — 2026-09-27', $from->toDateString().' — '.$to->toDateString());
    }

    public function test_period_label(): void
    {
        $this->assertSame('21–27 сентября 2026',
            MediaDataService::periodLabel(Carbon::parse('2026-09-21'), Carbon::parse('2026-09-27 23:59:59')));
        $this->assertSame('25 сентября – 1 октября 2026',
            MediaDataService::periodLabel(Carbon::parse('2026-09-25'), Carbon::parse('2026-10-01')));
        $this->assertSame('29 декабря 2026 – 4 января 2027',
            MediaDataService::periodLabel(Carbon::parse('2026-12-29'), Carbon::parse('2027-01-04')));
    }

    public function test_feed_failure_is_not_a_crash(): void
    {
        Http::fake(['*' => Http::response('oops', 500)]);

        $this->assertNull((new IndustryNewsFeed)->sync());
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
