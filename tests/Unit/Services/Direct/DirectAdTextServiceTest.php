<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectAdTextService as Texts;
use Tests\TestCase;

/**
 * Тексты объявлений от модели. Главное здесь — две проверки: на выдумку
 * («подходит для KONE», которого нет в карточке, это претензия клиента и отказ
 * модерации) и на то, за что снимают с модерации: цену, превосходную степень,
 * КАПС. Без БД и без сети.
 */
class DirectAdTextServiceTest extends TestCase
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
            Texts::cleanAnswer("«Гребёнка OTIS 506NCE для эскалатора».\nЭто вариант заголовка.")
        );
    }

    public function test_too_long_answer_is_cut_by_words(): void
    {
        $long = 'Гребёнка центральная OTIS 506NCE XO-508 для эскалатора в наличии со склада Москва';
        $clean = Texts::cleanAnswer($long);

        $this->assertNotNull($clean);
        $this->assertLessThanOrEqual(56, mb_strlen($clean));
    }

    public function test_empty_or_tiny_answer_is_rejected(): void
    {
        $this->assertNull(Texts::cleanAnswer(''));
        $this->assertNull(Texts::cleanAnswer('   '));
        $this->assertNull(Texts::cleanAnswer('ок'));
    }

    public function test_faithful_title_passes(): void
    {
        $this->assertTrue(Texts::isFaithful('Гребёнка OTIS 506NCE для эскалатора', $this->item()));
        // Код из articles, которого нет в названии, тоже свой.
        $this->assertTrue(Texts::isFaithful('Гребёнка XO-508 OTIS', $this->item()));
    }

    public function test_invented_brand_is_rejected(): void
    {
        $this->assertFalse(Texts::isFaithful('Гребёнка OTIS 506NCE, подходит для KONE', $this->item()));
    }

    public function test_invented_article_is_rejected(): void
    {
        $this->assertFalse(Texts::isFaithful('Гребёнка OTIS GAA402BNP3', $this->item()));
    }

    public function test_russian_rewording_is_allowed(): void
    {
        // Русские слова модель вправе переформулировать — проверяем только
        // латиницу и цифры, иначе любой синоним ронял бы результат.
        $this->assertTrue(Texts::isFaithful('Центральная гребёнка эскалатора OTIS', $this->item()));
    }

    public function test_price_and_discounts_are_refused(): void
    {
        $this->assertTrue(Texts::violatesRules('Гребёнка OTIS от 5000 руб', 'text'));
        $this->assertTrue(Texts::violatesRules('Скидка на гребёнки OTIS', 'text'));
        $this->assertTrue(Texts::violatesRules('Гребёнка OTIS дешевле аналогов', 'text'));
    }

    public function test_superlatives_and_caps_are_refused(): void
    {
        $this->assertTrue(Texts::violatesRules('Лучшие гребёнки OTIS на складе', 'text'));
        $this->assertTrue(Texts::violatesRules('Гребёнка OTIS №1 в России', 'text'));
        $this->assertTrue(Texts::violatesRules('ГРЕБЁНКА OTIS в наличии', 'title'));
        // Латиница капсом — это бренды и артикулы, они законны.
        $this->assertFalse(Texts::violatesRules('Гребёнка OTIS 506NCE в наличии', 'title'));
    }

    public function test_cyrillic_caps_from_the_card_are_legal(): void
    {
        // МЕЧЕЛ, ГОСТ, УИРФ — это бренды и обозначения из карточки, не крик.
        $rope = (object) [
            'sku' => 'M07441',
            'name' => 'Канат d=7,8 мм ГОСТ 3077-80 грузовой',
            'brand' => 'МЕЧЕЛ (БМК)',
            'articles' => ['УИРФ 469135.055'],
            'part_type' => 'Канат грузовой',
        ];

        $this->assertFalse(Texts::shouts('Канат МЕЧЕЛ ГОСТ 3077-80 в наличии', $rope));
        $this->assertFalse(Texts::shouts('Арт. УИРФ 469135.055, есть на складе', $rope));
        // Слово не из карточки — крик.
        $this->assertTrue(Texts::shouts('Канат МЕЧЕЛ СРОЧНО со склада', $rope));
        // Всё из карточки, но набрано капсом целиком — тоже крик.
        $this->assertTrue(Texts::shouts('КАНАТ МЕЧЕЛ ГОСТ В НАЛИЧИИ', $rope));
    }

    public function test_exclamation_is_allowed_only_in_text_and_only_in_loud_tones(): void
    {
        $this->assertTrue(Texts::violatesRules('Гребёнка OTIS в наличии!', 'title', 'energetic'));
        $this->assertTrue(Texts::violatesRules('Есть на складе!', 'text', 'official'));
        $this->assertFalse(Texts::violatesRules('Есть на складе!', 'text', 'energetic'));
        $this->assertTrue(Texts::violatesRules('Есть на складе! Отгрузим сразу!', 'text', 'energetic'));
    }

    public function test_tautology_in_the_second_headline_is_refused(): void
    {
        // Кейс M00073: «Собранный контакт в сборе» — и повтор внутри фразы,
        // и слово «контакт» уже сказано в первом заголовке.
        $this->assertTrue(Texts::isTautology('Собранный контакт в сборе'));
        $this->assertTrue(Texts::isTautology('Контакт двери с активатором', 'Контакт двери Bernstein SEL2-A1Z P'));
        // Дополнение, а не повтор — так и надо.
        $this->assertFalse(Texts::isTautology('Bernstein · отгрузка сразу', 'Контакт двери Bernstein SEL2-A1Z P'));
        $this->assertFalse(Texts::isTautology('Выключатель безопасности', 'Контакт двери Bernstein SEL2-A1Z P'));
    }

    public function test_second_headline_is_checked_against_the_headline(): void
    {
        $item = $this->item();

        $this->assertNull(Texts::acceptField('Гребёнка в сборе', 'title2', $item, null, 'Гребёнка OTIS 506NCE'));
        $this->assertSame(
            'OTIS · со склада',
            Texts::acceptField('OTIS · со склада', 'title2', $item, null, 'Гребёнка центральная 506NCE'),
        );
    }

    public function test_json_answer_is_decoded(): void
    {
        $raw = '```json'."\n".'{"title":"Гребёнка OTIS 506NCE","title2":"OTIS · со склада","text":"Есть на складе"}'."\n".'```';
        $decoded = Texts::decode($raw);

        $this->assertSame('Гребёнка OTIS 506NCE', $decoded['title']);
        $this->assertSame('OTIS · со склада', $decoded['title2']);
    }

    public function test_plain_answer_becomes_the_title(): void
    {
        $this->assertSame(['title' => 'Гребёнка OTIS 506NCE'], Texts::decode('Гребёнка OTIS 506NCE'));
        $this->assertNull(Texts::decode(''));
    }

    public function test_field_is_accepted_or_dropped_by_its_own_limit(): void
    {
        $item = $this->item();

        $this->assertSame('OTIS · со склада', Texts::acceptField('OTIS · со склада', 'title2', $item));
        // Второй заголовок длиннее 30 — режется по словам, а не отбрасывается.
        $accepted = Texts::acceptField('OTIS со склада в наличии отгрузка в день обращения', 'title2', $item);
        $this->assertNotNull($accepted);
        $this->assertLessThanOrEqual(30, mb_strlen($accepted));
        // …а выдумка отбрасывается целиком.
        $this->assertNull(Texts::acceptField('Подходит для KONE', 'title2', $item));
    }
}
