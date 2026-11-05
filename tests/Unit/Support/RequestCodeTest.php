<?php

namespace Tests\Unit\Support;

use App\Support\RequestCode;
use PHPUnit\Framework\TestCase;

class RequestCodeTest extends TestCase
{
    public function test_both_formats_are_found_in_mail_text_and_normalized(): void
    {
        $this->assertSame('MZ2026-00499', RequestCode::find('Re: КП по заявке [MZ2026-00499] / [372901]'));
        $this->assertSame('MZ2026-00499', RequestCode::find('ответ по mz2026-00499'));
        $this->assertSame('M-2026-21748', RequestCode::find('Re: [M-2026-21748] / [372422]'));
        $this->assertSame(['M-2026-18000', 'MZ2026-00012'], RequestCode::all('M-2026-18000 и MZ2026-00012, ещё раз M-2026-18000'));
        $this->assertNull(RequestCode::find('Счёт 372431 от 05.11.2026'));
        $this->assertNull(RequestCode::find('артикул M14140'));
        $this->assertTrue(RequestCode::is('MZ2026-00499'));
        $this->assertTrue(RequestCode::is('M-2026-206'));
        $this->assertFalse(RequestCode::is('R-2026-0840'));
    }
}
