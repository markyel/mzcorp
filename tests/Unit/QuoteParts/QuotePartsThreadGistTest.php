<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsThread;
use Tests\TestCase;

/** Суть письма в компактной ленте переписки: без подписи и цитаты, в одну строку. */
class QuotePartsThreadGistTest extends TestCase
{
    public function test_signature_is_cut(): void
    {
        $text = "Hi Steve\nDo you offer original or copy?\n\n--\nС уважением / With best regards,\nАлександр Роденков / Alexander Rodenkov\nтел: +7 (495) 565-37-72";

        $this->assertSame('Hi Steve Do you offer original or copy?', QuotePartsThread::gist($text));
    }

    public function test_quoted_tail_is_cut(): void
    {
        $text = "Price is 3.6 USD, quality OK.\n\nOn 22.10.2026, MyZip <rfq@myzip.ru> wrote:\n> Do you offer original or copy?";

        $this->assertSame('Price is 3.6 USD, quality OK.', QuotePartsThread::gist($text));
    }

    public function test_split_keeps_line_breaks_and_moves_signature_to_the_tail(): void
    {
        $text = "Привет, Александр!\r\n\r\nХорошая копия — это высококачественная имитация.\r\n\r\n\r\n\r\n\r\nС уважением,\r\nСвета\r\nXi'an YuanQi Elevator Parts Co.,Ltd\r\n\r\n------------------ Original ------------------\r\nFrom: MyZip";

        [$own, $tail] = QuotePartsThread::split($text);

        $this->assertSame("Привет, Александр!\n\nХорошая копия — это высококачественная имитация.", $own);
        $this->assertStringStartsWith('С уважением,', $tail);
        $this->assertStringContainsString('Original', $tail);
    }

    public function test_split_without_signature_has_no_tail(): void
    {
        $this->assertSame(['Price is 3.6 USD.', ''], QuotePartsThread::split("Price is 3.6 USD.\n"));
    }

    public function test_long_text_is_limited(): void
    {
        $gist = QuotePartsThread::gist(str_repeat('word ', 100));
        $this->assertLessThanOrEqual(223, mb_strlen($gist));
        $this->assertStringEndsWith('...', $gist);
    }
}
