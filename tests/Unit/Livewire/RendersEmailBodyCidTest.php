<?php

namespace Tests\Unit\Livewire;

use App\Livewire\Concerns\RendersEmailBody;
use Tests\TestCase;

/**
 * Подстановка cid: в теле письма. Главный кейс — картинка из ЦИТАТЫ: почтовые
 * клиенты сохраняют `<img src="cid:image001.png@…">` в ответе, но сам файл в
 * ответ не вкладывают. Ссылаться на такой cid нельзя — браузер получал 404 на
 * каждый рендер письма (203 из 500 свежих писем со ссылками cid).
 */
class RendersEmailBodyCidTest extends TestCase
{
    public function test_normalizes_content_id_for_comparison(): void
    {
        $this->assertSame(
            'image001.png@01dd4770.f9795',
            RendersEmailBody::normalizeContentId('<image001.png@01DD4770.F9795>')
        );
        $this->assertSame('yotk@lchqcnwi', RendersEmailBody::normalizeContentId(' Yotk%40lCHQCnWI '));
        $this->assertSame('', RendersEmailBody::normalizeContentId(null));
        $this->assertSame('', RendersEmailBody::normalizeContentId('  <>  '));
    }

    public function test_angle_brackets_and_case_do_not_break_the_match(): void
    {
        // Отправители пишут content_id и в скобках, и без, и в разном регистре —
        // после нормализации это одна и та же строка.
        $stored = RendersEmailBody::normalizeContentId('<ABC123@mail.local>');
        $inHtml = RendersEmailBody::normalizeContentId('abc123@Mail.Local');

        $this->assertSame($stored, $inHtml);
    }

    public function test_percent_encoded_cid_matches_plain_one(): void
    {
        $this->assertSame(
            RendersEmailBody::normalizeContentId('part1.04@yandex'),
            RendersEmailBody::normalizeContentId('part1.04%40yandex')
        );
    }
}
