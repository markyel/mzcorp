<?php

namespace Tests\Unit\Models;

use App\Models\MailLabel;
use Tests\TestCase;

/**
 * Метки писем: нормализация имени (словарь общий, поэтому «Тендер» и «тендер»
 * не должны разъезжаться) и палитра. Без БД.
 */
class MailLabelTest extends TestCase
{
    public function test_name_is_trimmed_and_inner_spaces_collapsed(): void
    {
        $this->assertSame('Срочно', MailLabel::normalizeName('  Срочно  '));
        $this->assertSame('Ждём оплату', MailLabel::normalizeName("Ждём\n\t  оплату"));
    }

    public function test_name_is_cut_to_the_column_length(): void
    {
        $long = str_repeat('я', MailLabel::NAME_MAX + 20);

        $this->assertSame(MailLabel::NAME_MAX, mb_strlen(MailLabel::normalizeName($long)));
    }

    public function test_empty_name_stays_empty(): void
    {
        $this->assertSame('', MailLabel::normalizeName('   '));
        $this->assertSame('', MailLabel::normalizeName(null));
    }

    public function test_colors_are_validated_against_the_palette(): void
    {
        $this->assertTrue(MailLabel::isValidColor('amber'));
        $this->assertFalse(MailLabel::isValidColor('#ff0000'));
        $this->assertFalse(MailLabel::isValidColor(null));
    }

    public function test_unknown_color_falls_back_to_neutral(): void
    {
        $label = new MailLabel(['name' => 'Тест', 'color' => 'магента']);

        $this->assertSame(MailLabel::COLORS['neutral'][0], $label->bg());
        $this->assertSame(MailLabel::COLORS['neutral'][1], $label->fg());
    }

    public function test_known_color_renders_its_own_tokens(): void
    {
        $label = new MailLabel(['name' => 'Тендер', 'color' => 'emerald']);

        $this->assertSame(MailLabel::COLORS['emerald'][0], $label->bg());
        $this->assertSame(MailLabel::COLORS['emerald'][1], $label->fg());
    }
}
