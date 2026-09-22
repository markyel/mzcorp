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

    public function test_cut_title_never_leaves_an_open_bracket(): void
    {
        // Живой кейс: «…FCU 0735X (FS0735) (24V DC, 35» — скобка открылась,
        // закрывающая не влезла, объявление читалось обрывком.
        $title = Plan::adTitle($this->item([
            'name' => 'Фотозавеса динамическая FCU 0735X (FS0735) (24V DC, 35 лучей)',
        ]));

        $this->assertLessThanOrEqual(Plan::TITLE_MAX, mb_strlen($title));
        $this->assertSame(mb_substr_count($title, '('), mb_substr_count($title, ')'));
        $this->assertStringEndsNotWith(',', $title);
        $this->assertSame('Фотозавеса динамическая FCU 0735X (FS0735)', $title);
    }

    public function test_tidy_tail_removes_dangling_punctuation(): void
    {
        $this->assertSame('Плата LCD', Plan::tidyTail('Плата LCD ('));
        $this->assertSame('Плата LCD', Plan::tidyTail('Плата LCD, '));
        $this->assertSame('Ремень [A]', Plan::tidyTail('Ремень [A] [B'));
        $this->assertSame('Плата (v2)', Plan::tidyTail('Плата (v2)'));
    }

    public function test_cut_title_stays_valid_utf8(): void
    {
        // Кейс 22.09: заголовок, обрезанный на букве «р» (d1 80), терял второй
        // байт в rtrim() со списком многобайтовых тире — Postgres отвергал
        // строку и ронял весь прогон синхронизации.
        $title = Plan::adTitle($this->item([
            'name' => 'ГРЕБЕНКА ЦЕНТРАЛЬНАЯ OTIS 506NCE и XO-508, БЕЗ КРЕПЕЖА, серый цвет опор',
        ]));

        $this->assertTrue(mb_check_encoding($title, 'UTF-8'), 'заголовок обязан остаться валидным UTF-8');
        $this->assertLessThanOrEqual(Plan::TITLE_MAX, mb_strlen($title));
    }

    public function test_cut_never_ends_on_a_conjunction_or_preposition(): void
    {
        // Кейс M15556: второй заголовок «Поручень для эскалатора и
        // траволатора» резался по лимиту и оставлял висящий союз.
        $this->assertSame('Поручень для эскалатора', Plan::tidyTail('Поручень для эскалатора и'));
        $this->assertSame('Направляющая поручня', Plan::tidyTail('Направляющая поручня для'));
        $this->assertSame('Отгрузка со склада', Plan::tidyTail('Отгрузка со склада и из'));
        // Осмысленный хвост не трогаем.
        $this->assertSame('Поручень эскалатора', Plan::tidyTail('Поручень эскалатора'));
    }

    public function test_title_falls_back_to_brand_and_sku(): void
    {
        $title = Plan::adTitle($this->item(['name' => '']));

        $this->assertStringContainsString('OTIS', $title);
        $this->assertStringContainsString('M00193', $title);
    }

    public function test_second_title_takes_the_brand_when_the_headline_lacks_it(): void
    {
        // Заголовок про поручень без бренда — во втором ставим бренд.
        $title2 = Plan::adTitle2($this->item([
            'name' => 'Поручень резиновый тип C699 чёрный',
            'brand' => 'Schindler',
            'part_type' => 'Поручень эскалатора и траволатора',
        ]));

        $this->assertSame('Schindler, со склада', $title2);
        $this->assertLessThanOrEqual(Plan::TITLE2_MAX, mb_strlen($title2));
    }

    public function test_second_title_switches_to_the_assembly_when_the_brand_is_already_in_the_headline(): void
    {
        $item = $this->item(['part_type' => 'Поручень эскалатора и траволатора']);
        // Заголовок «Гребёнка центральная OTIS 506NCE…» бренд уже содержит.
        $title2 = Plan::adTitle2($item, Plan::adTitle($item));

        $this->assertStringNotContainsString('OTIS', $title2);
        $this->assertStringContainsString('Поручень эскалатора', $title2);
    }

    public function test_second_titles_differ_across_positions(): void
    {
        $a = Plan::adTitle2($this->item(['name' => 'Поручень C699', 'brand' => 'Schindler']));
        $b = Plan::adTitle2($this->item(['name' => 'Ролик каретки', 'brand' => 'Fermator']));

        $this->assertNotSame($a, $b, 'второй заголовок обязан различаться между позициями');
    }

    public function test_second_title_falls_back_when_there_is_nothing_to_say(): void
    {
        $title2 = Plan::adTitle2($this->item(['brand' => '', 'part_type' => '', 'name' => 'Деталь']));

        $this->assertSame('Отгрузка со склада', $title2);
    }

    public function test_short_part_type_cuts_the_enumeration(): void
    {
        $this->assertSame('Поручень эскалатора', Plan::shortPartType(
            (object) ['part_type' => 'Поручень эскалатора и траволатора']
        ));
        $this->assertSame('Ролик подвеса ДК', Plan::shortPartType(
            (object) ['part_type' => 'Ролик подвеса ДК/ДШ']
        ));
        $this->assertSame('', Plan::shortPartType((object) []));
    }

    public function test_text_fits_the_limits(): void
    {
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

    public function test_characters_direct_refuses_are_stripped(): void
    {
        // Кейс M05236: «безопасность проема лифта ·» — из-за типографской
        // точки Директ не создал объявление целиком.
        $this->assertSame('безопасность проема лифта', Plan::tidyTail('безопасность проема лифта ·'));
        $this->assertSame('OTIS со склада', Plan::tidyTail('OTIS · со склада'));
        // Обычная пунктуация остаётся.
        $this->assertSame('Арт. E10 18 (MEMCO)', Plan::tidyTail('Арт. E10 18 (MEMCO)'));
    }

    public function test_shouting_catalog_names_are_calmed_but_brands_are_not(): void
    {
        // Кейс M07484: «Коннектор С РАЗЪЕМАМИ тяговых ремней» — модерация
        // отклонила. Каталог пишут для склада, там капсом выделяют что угодно.
        $item = $this->item([
            'name' => 'Коннектор С РАЗЪЕМАМИ тяговых ремней 30мм 43кН',
            'brand' => 'Semperit',
        ]);

        $title = Plan::adTitle($item);
        $this->assertStringContainsString('разъемами', $title);
        $this->assertStringNotContainsString('РАЗЪЕМАМИ', $title);

        // Бренд и обозначение заглавными — это имена, а не крик.
        $rope = $this->item([
            'name' => 'Канат МЕЧЕЛ ГОСТ 3077-80 грузовой',
            'brand' => 'МЕЧЕЛ',
            'articles' => ['ГОСТ 3077-80'],
            'brand_article' => 'ГОСТ 3077-80',
        ]);
        $this->assertStringContainsString('МЕЧЕЛ', Plan::adTitle($rope));
        $this->assertStringContainsString('ГОСТ', Plan::adTitle($rope));
    }

    public function test_operators_are_not_part_of_an_article(): void
    {
        // «+» и ведущий «-» Директ читает как операторы: фраза «p2609+p2611»
        // отбивается при создании («неправильное использование знака +»).
        $this->assertSame('p2609 p2611', Plan::normalizeKeyword('P2609+P2611'));
        $this->assertSame('хо 508', Plan::normalizeKeyword('-ХО 508'));
        // Дефис внутри кода — часть артикула, его не трогаем.
        $this->assertSame('xo-508', Plan::normalizeKeyword('XO-508'));
    }

    public function test_keyword_words_are_counted_the_way_direct_counts_them(): void
    {
        // Кейс M07441: по пробелам у нас выходило 4 слова, Директ насчитал 11
        // и отбил фразу при создании.
        $this->assertNull(Plan::normalizeKeyword('7,8-Г-В-Н-Р-Т-1770 ГОСТ 3077-80'));
        $this->assertSame('601.6369.049 купить', Plan::normalizeKeyword('601.6369.049 купить'));
    }

    public function test_cut_head_is_closed_with_a_full_stop(): void
    {
        // Кейс M07441: «…1770 ГОСТ Есть на складе» читалось как оборванная
        // фраза — обрезанная голова обязана закрываться точкой.
        $text = Plan::adText($this->item([
            'brand' => 'МЕЧЕЛ (БМК)',
            'articles' => ['7,8-Г-В-Н-Р-Т-1770 ГОСТ 3077-80'],
            'brand_article' => '7,8-Г-В-Н-Р-Т-1770 ГОСТ 3077-80',
        ]));

        $this->assertLessThanOrEqual(Plan::TEXT_MAX, mb_strlen($text));
        $this->assertStringNotContainsString('ГОСТ Есть', $text);
        $this->assertStringContainsString('. Есть на складе', $text);
    }

    public function test_double_spaces_of_the_catalog_name_are_squeezed(): void
    {
        $title = Plan::adTitle($this->item(['name' => 'Канат  d=7,8 мм грузовой']));

        $this->assertStringNotContainsString('  ', $title);
    }

    public function test_price_never_appears_in_the_ad(): void
    {
        // Решение заказчика: цену не публикуем — на карточке её анонимно не видно.
        $item = $this->item();
        foreach ([Plan::adTitle($item), Plan::adTitle2($item), Plan::adText($item)] as $part) {
            $this->assertStringNotContainsString('1070', $part);
            $this->assertStringNotContainsString('₽', $part);
        }
    }

    public function test_keywords_are_the_codes_themselves(): void
    {
        $keywords = Plan::keywords($this->item());

        $this->assertContains('506nce', $keywords);
        $this->assertContains('xo-508', $keywords);
        // «артикул купить» мы больше не выдумываем: такую строку не набирают,
        // а место в группе она занимала. Человеческие фразы — themeKeywords().
        $this->assertNotContains('506nce купить', $keywords);
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
