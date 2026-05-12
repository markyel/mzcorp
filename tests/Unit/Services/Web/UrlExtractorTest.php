<?php

namespace Tests\Unit\Services\Web;

use App\Services\Web\UrlExtractor;
use App\Services\Web\WebSecurity;
use PHPUnit\Framework\TestCase;

class UrlExtractorTest extends TestCase
{
    private UrlExtractor $ext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ext = new UrlExtractor(new WebSecurity());
    }

    public function test_extracts_urls_from_plain_text(): void
    {
        $plain = 'Прошу https://mylift.ru/?code=M20732&fn=view и https://mylift.ru/index.php?code=M23658&fn=view .';
        $out = $this->ext->extract($plain, null, 10);

        $this->assertCount(2, $out);
        $this->assertContains('https://mylift.ru/?code=M20732&fn=view', $out);
        $this->assertContains('https://mylift.ru/index.php?code=M23658&fn=view', $out);
    }

    public function test_strips_trailing_punctuation(): void
    {
        $out = $this->ext->extract('See https://example.com/page.', null);
        $this->assertSame(['https://example.com/page'], $out);
    }

    public function test_deduplicates_normalized_urls(): void
    {
        $plain = 'Дубль https://Example.com/?b=2&a=1 и https://example.com/?a=1&b=2';
        $out = $this->ext->extract($plain, null);
        $this->assertCount(1, $out);
    }

    public function test_extracts_href_from_html(): void
    {
        $html = '<p>Click <a href="https://mylift.ru/?code=M20732&amp;fn=view">here</a></p>';
        $out = $this->ext->extract(null, $html);
        $this->assertContains('https://mylift.ru/?code=M20732&fn=view', $out);
    }

    public function test_ignores_non_anchor_attrs(): void
    {
        $html = '<img src="https://tracker.example/x.gif"><script src="https://evil.example/x.js"></script><a href="https://ok.example/">ok</a>';
        $out = $this->ext->extract(null, $html);
        $this->assertSame(['https://ok.example/'], $out);
    }

    public function test_rejects_non_http_schemes(): void
    {
        $plain = 'Ссылка mailto:foo@bar.com и file:///etc/passwd и javascript:alert(1)';
        $out = $this->ext->extract($plain, null);
        $this->assertSame([], $out);
    }

    public function test_respects_max_cap(): void
    {
        $plain = implode(' ', array_map(fn ($i) => "https://example.com/{$i}", range(1, 20)));
        $out = $this->ext->extract($plain, null, 5);
        $this->assertCount(5, $out);
    }
}
