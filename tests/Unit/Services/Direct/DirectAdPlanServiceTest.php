<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectAdPlanService as Plan;
use Tests\TestCase;

/**
 * Тексты и фразы объявления по складской позиции. Проверяем ограничения
 * Директа: заголовок 56, второй 30, текст 81, фраза — не больше семи слов и
 * без служебных символов. Без БД и без сети.
 */
class DirectAdPlanServiceTest extends TestCase
{
    private function item(array $over = []): object
    {
        return (object) array_merge([
            'sku' => 'M00193',
            'name' => 'Гребёнка центральная OTIS 506NCE',
            'brand' => 'OTIS',
            'brand_article' => '506NCE',
            'articles' => ['506NCE', 'XO-508'],
            'price' => 1070,
            'stock_available' => 103,
        ], $over);
    }

    public function test_title_adds_availability_when_it_fits(): void
    {
        $title = Plan::adTitle($this->item());

        $this->assertSame('Гребёнка центральная OTIS 506NCE — в наличии', $title);
        $this->assertLessThanOrEqual(Plan::TITLE_MAX, mb_strlen($title));
    }

    public function test_long_name_is_cut_on_a_word_boundary(): void
    {
        $title = Plan::adTitle($this->item([
            'name' => 'Поручень резиновый тип 1879-O для эскалатора OTIS-800 чёрный Slimline',
        ]));

        $this->assertLessThanOrEqual(Plan::TITLE_MAX, mb_strlen($title));
        $this->assertStringEndsNotWith('-', $title);
        // Обрезали по словам — последнее слово целое.
        $this->assertStringNotContainsString('  ', $title);
    }

    public function test_title_falls_back_to_brand_and_sku(): void
    {
        $title = Plan::adTitle($this->item(['name' => '']));

        $this->assertStringContainsString('OTIS', $title);
        $this->assertStringContainsString('M00193', $title);
    }

    public function test_second_title_and_text_fit_the_limits(): void
    {
        $this->assertLessThanOrEqual(Plan::TITLE2_MAX, mb_strlen(Plan::adTitle2()));

        $text = Plan::adText($this->item());
        $this->assertLessThanOrEqual(Plan::TEXT_MAX, mb_strlen($text));
        $this->assertStringContainsString('506NCE', $text);
        $this->assertStringContainsString('счёт в день обращения', $text);
    }

    public function test_text_keeps_the_promise_when_brand_is_long(): void
    {
        $text = Plan::adText($this->item([
            'brand' => 'Очень длинное наименование производителя из каталога',
            'articles' => ['ABCDEFGH123456'],
            'brand_article' => 'ABCDEFGH123456',
        ]));

        $this->assertLessThanOrEqual(Plan::TEXT_MAX, mb_strlen($text));
        $this->assertStringContainsString('счёт в день обращения', $text);
    }

    public function test_price_never_appears_in_the_ad(): void
    {
        // Решение заказчика: цену не публикуем — на карточке её анонимно не видно.
        $item = $this->item();
        foreach ([Plan::adTitle($item), Plan::adTitle2(), Plan::adText($item)] as $part) {
            $this->assertStringNotContainsString('1070', $part);
            $this->assertStringNotContainsString('₽', $part);
        }
    }

    public function test_keywords_are_codes_plus_buy(): void
    {
        $keywords = Plan::keywords($this->item());

        $this->assertContains('506nce', $keywords);
        $this->assertContains('506nce купить', $keywords);
        $this->assertContains('xo-508', $keywords);
        $this->assertLessThanOrEqual(Plan::KEYWORDS_PER_GROUP, count($keywords));
    }

    public function test_keywords_are_empty_without_codes(): void
    {
        $this->assertSame([], Plan::keywords($this->item(['articles' => [], 'brand_article' => ''])));
    }

    public function test_keyword_normalisation_strips_service_characters(): void
    {
        $this->assertSame('6210-2rs1 c3', Plan::normalizeKeyword('6210-2RS1 (C3)'));
        $this->assertSame('km283288g01', Plan::normalizeKeyword('KM283288G01!'));
    }

    public function test_keyword_rejects_too_short_or_too_many_words(): void
    {
        $this->assertNull(Plan::normalizeKeyword('!!'));
        $this->assertNull(Plan::normalizeKeyword('a b c d e f g h'));
        $this->assertNull(Plan::normalizeKeyword(''));
    }

    public function test_group_name_starts_with_the_article(): void
    {
        $name = Plan::groupName($this->item());

        $this->assertStringStartsWith('M00193', $name);
        $this->assertLessThanOrEqual(55, mb_strlen($name));
    }
}
