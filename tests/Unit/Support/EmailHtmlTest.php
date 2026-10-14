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
}
