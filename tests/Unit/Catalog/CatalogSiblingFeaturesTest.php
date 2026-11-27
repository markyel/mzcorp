<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\CatalogSiblingService;
use PHPUnit\Framework\TestCase;

/** Существенные признаки вариантов одной модели: надпись кнопки, сторона, цвет, комплектация. */
class CatalogSiblingFeaturesTest extends TestCase
{
    public function test_button_label_direction_and_color(): void
    {
        $f = CatalogSiblingService::features('Кнопка вызова "СТРЕЛКА ВВЕРХ" с шрифтом Брайля тип FMR SATIN красная индикация', false);
        $this->assertEqualsCanonicalizing(['СТРЕЛКА', 'ВВЕРХ'], $f['label']['values']);
        $this->assertSame(['КРАСН'], $f['color']['values']);

        $c = CatalogSiblingService::features('Кнопка вызова KLEEMANN круглая FMR BS (4 двухконт.разъема) такт.символ "ВНИЗ" с Брайлем крас.подсв', true);
        $this->assertSame(['ВНИЗ'], $c['label']['values']);
        $this->assertSame(['КРАСН'], $c['color']['values']);
    }

    public function test_floor_numbers_keep_the_minus(): void
    {
        $this->assertSame(['1'], CatalogSiblingService::features('Кнопка приказная (этаж 1)', false)['label']['values']);
        $this->assertSame(['-1'], CatalogSiblingService::features('Кнопка приказа KLEEMANN тактильный символ "-1" красная подсветка', true)['label']['values']);
        $this->assertSame(['7'], CatalogSiblingService::features('Кнопка приказа лифтовая круглая с маркировкой «7»', false)['label']['values']);
    }

    public function test_label_words_in_any_case_and_floor_before_the_word(): void
    {
        $this->assertSame(['ЗАКРЫТИЕ'], CatalogSiblingService::features('Кнопка закрытия дверей кабины DMG тип BFBX', false)['label']['values']);
        $this->assertSame(['ЗАКРЫТИЕ'], CatalogSiblingService::features('Кнопка приказа "ЗАКРЫТИЕ ДВЕРЕЙ" DMG', true)['label']['values']);
        $this->assertSame(['17'], CatalogSiblingService::features('Кнопка MT42-2M RUS 17 этаж с кодом Брайля', false)['label']['values']);
        $this->assertSame(['2'], CatalogSiblingService::features('кнопка на 2-й этаж', false)['label']['values']);
    }

    public function test_braille_is_a_feature_in_any_wording(): void
    {
        $this->assertSame(['Y'], CatalogSiblingService::features('Кнопка приказа "2" с кодом Брайля красная индикация', false)['braille']['values']);
        $this->assertSame(['Y'], CatalogSiblingService::features('Кнопка KLEEMANN тактил.символ "2" с Брайлем красн.подсв', true)['braille']['values']);
        $this->assertSame([], CatalogSiblingService::features('Кнопка KLEEMANN тактильный символ "2" красная подсветка', true)['braille']['values']);
    }

    public function test_quoted_names_are_not_labels(): void
    {
        $this->assertSame([], CatalogSiblingService::features('Лифтовой блок V7.2 диспетчерского комплекса "Обь"', true)['label']['values']);
    }

    public function test_presence_and_side(): void
    {
        $this->assertSame(['БЕЗ МЕХАНИ'], CatalogSiblingService::features('Контроллер привода дверей REVECO II-IV-GO! без механического замка', true)['presence']['values']);
        $this->assertSame(['С МЕХАНИ'], CatalogSiblingService::features('Плата контроллера привода дверей REVECO (с механическим замком)', true)['presence']['values']);
        $this->assertSame(['R'], CatalogSiblingService::features('Гребенка алюминиевая KONE TravelMaster 130x202mm правая 22 зуба', true)['side']['values']);
        $this->assertSame([], CatalogSiblingService::features('Ролик направляющий башмака Doppler, тип 85х30', true)['side']['values']);
        $this->assertSame(['C'], CatalogSiblingService::features('Гребёнка алюминиевая ЦЕНТРАЛЬНАЯ 130×197мм, 22 зуба', false)['side']['values']);
    }
}
