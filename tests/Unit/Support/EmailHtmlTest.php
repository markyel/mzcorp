<?php

namespace Tests\Unit\Support;

use App\Support\EmailHtml;
use PHPUnit\Framework\TestCase;

/**
 * Картинка подписи со ссылкой на файл телефона отправителя (почта iPhone не
 * приложила файл, M-2026-19436) — прячем; обычные картинки не трогаем.
 */
class EmailHtmlTest extends TestCase
{
    public function test_local_file_image_becomes_a_hidden_pixel(): void
    {
        $html = '<img style="width: 107px" src="file:///private/var/mobile/Containers/Data/Application/50041623/tmp/ContentImages/84cc7e66">';

        $out = EmailHtml::hideLocalImages($html);

        $this->assertStringNotContainsString('file:///', $out);
        $this->assertStringContainsString('data-cid-missing="1"', $out);
        $this->assertStringContainsString(EmailHtml::MISSING_IMAGE_PIXEL, $out);
    }

    public function test_web_inline_and_data_images_are_kept(): void
    {
        $html = '<img src="https://elevating.ru/logo.png"><img src=\'/dashboard/attachments/inline/5/abc\'><img src="data:image/png;base64,AAA">';

        $this->assertSame($html, EmailHtml::hideLocalImages($html));
    }

    public function test_cid_is_found_with_and_without_quotes(): void
    {
        // Lotus Notes (ДМА, MZ2026-00483) пишет src без кавычек; alt с «cid:» не трогаем.
        $html = '<img src=cid:_4_165D9E40 alt=cid:image003.png@01D4 style="b"><img src="cid:a@b"><a href=\'cid:x\'>l</a>';

        $out = preg_replace_callback(EmailHtml::CID_ATTR_PATTERN, function (array $m) {
            [$attr, $q, $cid] = EmailHtml::cidParts($m);

            return $attr.'='.$q.'<'.$cid.'>'.$q;
        }, $html);

        $this->assertSame('<img src="<_4_165D9E40>" alt=cid:image003.png@01D4 style="b"><img src="<a@b>"><a href=\'<x>\'>l</a>', $out);
    }

    public function test_the_declared_charset_becomes_utf8_only_in_meta(): void
    {
        // Outlook у Метеор Лифта (MZ2026-01418): письмо в KOI8-R, мы храним HTML в UTF-8, а мета осталась.
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=koi8-r"><META charset="windows-1251">'
            .'<meta charset=cp1251></head><body>Коллеги, charset=koi8-r в тексте не трогаем</body></html>';

        $this->assertSame(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><META charset="utf-8">'
            .'<meta charset=utf-8></head><body>Коллеги, charset=koi8-r в тексте не трогаем</body></html>',
            EmailHtml::declareUtf8($html),
        );
    }
}
