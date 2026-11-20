<?php

namespace Tests\Unit\Support;

use App\Support\EnglishText;
use PHPUnit\Framework\TestCase;

/** Английское название с кириллическими двойниками из русского (20.11.2026, заявка 21116: «Rope 250Т»). */
class EnglishTextTest extends TestCase
{
    public function test_lookalikes_in_an_english_name_become_latin(): void
    {
        // «Т» в 250Т и «о», «е» в «соre» — кириллица.
        $name = 'Rope 250Т d=6,5 mm, 8x19W-IWRC, 1570/1770 N/mm2, U, sZ, with steel соrе (DRAKO Germany)';

        $this->assertTrue(EnglishText::isEnglish($name));
        $this->assertSame('Rope 250T d=6,5 mm, 8x19W-IWRC, 1570/1770 N/mm2, U, sZ, with steel core (DRAKO Germany)', EnglishText::fold($name));
    }

    public function test_russian_text_stays_russian(): void
    {
        $this->assertFalse(EnglishText::isEnglish('Канат 250Т d=6,5 мм'));
        $this->assertFalse(EnglishText::isEnglish('Трос'), 'русское слово целиком из двойников');
        $this->assertSame('Трос', EnglishText::fold('Трос'));
        $this->assertFalse(EnglishText::isEnglish('  '));
        $this->assertTrue(EnglishText::isEnglish('Gearless machine G300 T0'));
    }
}
