<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\OutboundHtmlPostProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Пост-обработка HTML исходящего письма: ссылки на inline-роут → cid:,
 * email-safe стили для таблиц/картинок. Без БД.
 */
class OutboundHtmlPostProcessorTest extends TestCase
{
    private OutboundHtmlPostProcessor $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new OutboundHtmlPostProcessor();
    }

    public function test_cidify_replaces_inline_route_links_of_this_message_only(): void
    {
        $html = '<p>a</p><img src="https://mzcorp.ru/attachments/cid/42/abc%40mzcorp.ru?v=1">'
            .'<img src="/attachments/cid/42/def@mzcorp.ru">'
            .'<img src="https://mzcorp.ru/attachments/cid/43/other@mzcorp.ru">'
            .'<img src="https://example.com/x.png">';

        $r = $this->p->cidify($html, 42);

        $this->assertStringContainsString('src="cid:abc@mzcorp.ru"', $r['html']);
        $this->assertStringContainsString('src="cid:def@mzcorp.ru"', $r['html']);
        $this->assertStringContainsString('/attachments/cid/43/other@mzcorp.ru', $r['html']);
        $this->assertStringContainsString('https://example.com/x.png', $r['html']);
        $this->assertSame(['abc@mzcorp.ru', 'def@mzcorp.ru'], $r['cids']);
    }

    public function test_cidify_without_images_is_noop(): void
    {
        $r = $this->p->cidify('<p>plain</p>', 7);

        $this->assertSame('<p>plain</p>', $r['html']);
        $this->assertSame([], $r['cids']);
    }

    public function test_referenced_cids_includes_existing_cid_links(): void
    {
        $html = '<img src="cid:one@x"><img src="/attachments/cid/5/two%40x">';

        $this->assertSame(['two@x', 'one@x'], $this->p->referencedCids($html, 5));
    }

    public function test_email_safe_adds_inline_styles_to_tables_and_images(): void
    {
        $html = '<table><tbody><tr><th>Н</th><td></td></tr><tr><td style="color:red">x</td><td>y</td></tr></tbody></table>'
            .'<img src="cid:a@b" width="300"><blockquote>q</blockquote>';

        $out = $this->p->emailSafe($html);

        $this->assertStringContainsString('<table style="border-collapse:collapse;">', $out);
        $this->assertStringContainsString('<th style="border:1px solid #d0d5dd;padding:4px 8px;vertical-align:top;background:#f1f5f9;font-weight:600;text-align:left;">Н</th>', $out);
        $this->assertStringContainsString('<td style="color:red;border:1px solid #d0d5dd;padding:4px 8px;vertical-align:top;">x</td>', $out);
        $this->assertStringContainsString('&nbsp;</td>', $out); // пустая ячейка
        $this->assertStringContainsString('<img src="cid:a@b" width="300" style="max-width:100%;height:auto;">', $out);
        $this->assertStringContainsString('<blockquote style="margin:6px 0;padding-left:12px;border-left:2px solid #d0d5dd;color:#475569;">q</blockquote>', $out);
    }

    public function test_email_safe_leaves_plain_html_untouched(): void
    {
        $html = '<p>Привет, <b>мир</b></p>';

        $this->assertSame($html, $this->p->emailSafe($html));
    }
}
