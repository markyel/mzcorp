<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\HtmlSanitizer;
use Tests\TestCase;

/**
 * Санитайзер HTML из редактора письма: картинки только cid:/наш inline-роут,
 * style — белый список свойств, скрипты и обработчики вырезаются.
 */
class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $s;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://mzcorp.ru');
        $this->s = new HtmlSanitizer();
    }

    public function test_keeps_inline_route_and_cid_images_drops_external_and_data(): void
    {
        $html = '<img src="https://mzcorp.ru/attachments/cid/12/a%40b" width="300" alt="x">'
            .'<img src="/attachments/cid/12/c@d">'
            .'<img src="cid:e@f">'
            .'<img src="https://evil.example/t.gif">'
            .'<img src="data:image/png;base64,AAAA">'
            .'<img src="https://other.host/attachments/cid/12/z">';

        $out = $this->s->sanitize($html);

        $this->assertStringContainsString('<img src="https://mzcorp.ru/attachments/cid/12/a%40b" width="300" alt="x">', $out);
        $this->assertStringContainsString('<img src="/attachments/cid/12/c@d">', $out);
        $this->assertStringContainsString('<img src="cid:e@f">', $out);
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertStringNotContainsString('data:image', $out);
        $this->assertStringNotContainsString('other.host', $out);
    }

    public function test_style_whitelist(): void
    {
        $html = '<p style="text-align: center; color: #d32027; background: url(http://x/y.png); position: absolute">t</p>'
            .'<span style="color:red;font-size:14px;behavior:url(x)">s</span>'
            .'<td style="border:1px solid #ccc;padding:4px">c</td>';

        $out = $this->s->sanitize($html);

        $this->assertStringContainsString('<p style="text-align:center;color:#d32027">t</p>', $out);
        $this->assertStringContainsString('<span style="color:red;font-size:14px">s</span>', $out);
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('url(', $out);
        $this->assertStringNotContainsString('behavior', $out);
    }

    public function test_scripts_handlers_and_unknown_tags_are_removed(): void
    {
        $html = '<p onclick="alert(1)">a<script>alert(2)</script><custom>b</custom><s>c</s><mark>d</mark></p>';

        $out = $this->s->sanitize($html);

        $this->assertSame('<p>ab<s>c</s><mark>d</mark></p>', $out);
    }

    public function test_table_attributes(): void
    {
        $html = '<table width="100%" onload="x"><tr><td colspan="2" width="50%">a</td></tr></table>';

        $out = $this->s->sanitize($html);

        $this->assertStringContainsString('<table width="100%">', $out);
        $this->assertStringContainsString('<td colspan="2" width="50%">a</td>', $out);
        $this->assertStringNotContainsString('onload', $out);
    }
}
