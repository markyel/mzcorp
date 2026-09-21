<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectTitleService as Titles;
use Tests\TestCase;

/**
 * Заголовки от модели. Главное здесь — проверка на выдумку: в рекламе
 * запчастей «подходит для KONE», которого нет в карточке, это претензия
 * клиента и отказ модерации. Без БД и без сети.
 */
class DirectTitleServiceTest extends TestCase
{
    private function item(array $over = []): object
    {
        return (object) array_merge([
            'sku' => 'M00193',
            'name' => 'Гребёнка центральная OTIS 506NCE',
            'brand' => 'OTIS',
            'brand_article' => '506NCE',
            'articles' => ['506NCE', 'XO-508'],
            'part_type' => 'Гребёнка эскалатора',
        ], $over);
    }

    public function test_answer_is_reduced_to_one_clean_line(): void
    {
        $this->assertSame(
            'Гребёнка OTIS 506NCE для эскалатора',
            Titles::cleanAnswer("«Гребёнка OTIS 506NCE для эскалатора».\nЭто вариант заголовка.")
        );
    }

    public function test_too_long_answer_is_cut_by_words(): void
    {
        $long = 'Гребёнка центральная OTIS 506NCE XO-508 для эскалатора в наличии со склада Москва';
        $clean = Titles::cleanAnswer($long);

        $this->assertNotNull($clean);
        $this->assertLessThanOrEqual(56, mb_strlen($clean));
    }

    public function test_empty_or_tiny_answer_is_rejected(): void
    {
        $this->assertNull(Titles::cleanAnswer(''));
        $this->assertNull(Titles::cleanAnswer('   '));
        $this->assertNull(Titles::cleanAnswer('ок'));
    }

    public function test_faithful_title_passes(): void
    {
        $this->assertTrue(Titles::isFaithful('Гребёнка OTIS 506NCE для эскалатора', $this->item()));
        // Код из articles, которого нет в названии, тоже свой.
        $this->assertTrue(Titles::isFaithful('Гребёнка XO-508 OTIS', $this->item()));
    }

    public function test_invented_brand_is_rejected(): void
    {
        $this->assertFalse(Titles::isFaithful('Гребёнка OTIS 506NCE, подходит для KONE', $this->item()));
    }

    public function test_invented_article_is_rejected(): void
    {
        $this->assertFalse(Titles::isFaithful('Гребёнка OTIS GAA402BNP3', $this->item()));
    }

    public function test_russian_rewording_is_allowed(): void
    {
        // Русские слова модель вправе переформулировать — проверяем только
        // латиницу и цифры, иначе любой синоним ронял бы результат.
        $this->assertTrue(Titles::isFaithful('Центральная гребёнка эскалатора OTIS', $this->item()));
    }
}
