<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectAdPlanService as Plan;
use PHPUnit\Framework\TestCase;

/**
 * Фразы из типа детали и бренда — то, как деталь называет человек.
 *
 * Голый артикул производителя не ищут: замер 22.09.2026 — 274 наши фразы из
 * 382 с нулём показов в месяц, а «поручень эскалатора» — 1 601.
 */
class DirectThemeKeywordsTest extends TestCase
{
    private function item(array $fields): object
    {
        return (object) array_merge([
            'sku' => 'M00001', 'name' => '', 'brand' => '', 'brand_article' => '',
            'part_type' => '', 'unit_name' => '', 'articles' => null,
        ], $fields);
    }

    public function test_the_catalogue_classifier_becomes_a_human_phrase(): void
    {
        $this->assertSame('поручень эскалатора', Plan::partTypePhrase($this->item([
            'part_type' => 'Поручень эскалатора и траволатора',
        ])));

        // Перечисление режем по первой запятой: человек ищет «башмак», а не
        // «башмак, вкладыш башмака направляющих кабины и противовеса».
        $this->assertSame('башмак лифта', Plan::partTypePhrase($this->item([
            'part_type' => 'Башмак, вкладыш башмака направляющих кабины и противовеса',
        ])));

        // Скобочные пояснения — тоже не поисковый запрос.
        $this->assertSame('энкодер лифта', Plan::partTypePhrase($this->item([
            'part_type' => 'Энкодер, инкодер, тахометр (ДК, гл. привода, шахтный)',
        ])));
    }

    public function test_a_phrase_without_a_lift_word_gets_one(): void
    {
        // «Контроллер» — это геймпад (1,07 млн показов в месяц), «канат» —
        // верёвка. Без слова про лифт тематическая фраза приводит чужих.
        $this->assertSame('плата станции управления лифта', Plan::partTypePhrase($this->item([
            'part_type' => 'Плата станции управления, Главный контроллер',
        ])));

        // Узел берём из контекста: ступени бывают у эскалатора, не у лифта.
        $this->assertSame('гребенка эскалатора', Plan::partTypePhrase($this->item([
            'part_type' => 'Гребенка эскалатора',
        ])));
        $this->assertSame('цепь тяговая эскалатора', Plan::partTypePhrase($this->item([
            'part_type' => 'Цепь тяговая',
            'unit_name' => 'Ступенчатое полотно (ступени/паллеты, тяговые цепи)',
        ])));
    }

    public function test_the_brand_is_one_word(): void
    {
        $this->assertSame('thyssenkrupp', Plan::brandWord($this->item(['brand' => 'ThyssenKrupp Elevator (TKE)'])));
        $this->assertSame('skg', Plan::brandWord($this->item(['brand' => 'SKG (China handrails)'])));
        $this->assertSame('', Plan::brandWord($this->item(['brand' => ''])));
    }

    public function test_two_phrases_per_position_type_and_type_with_brand(): void
    {
        $phrases = Plan::themeKeywords($this->item([
            'part_type' => 'Поручень эскалатора и траволатора',
            'brand' => 'Semperit',
        ]));

        $this->assertSame(['поручень эскалатора', 'поручень эскалатора semperit'], $phrases);
    }

    public function test_only_measured_demand_gets_into_the_plan(): void
    {
        $codes = ['1879-o'];
        $themes = ['поручень эскалатора', 'поручень эскалатора semperit'];

        // Непомеренная фраза ждёт: место в группе она не занимает.
        $this->assertSame($codes, Plan::withThemes($codes, $themes, []));

        // Нулевой спрос — тоже мимо: по такому запросу аукциона нет вообще.
        $this->assertSame($codes, Plan::withThemes($codes, $themes, ['поручень эскалатора' => 0]));

        $this->assertSame(
            ['1879-o', 'поручень эскалатора'],
            Plan::withThemes($codes, $themes, ['поручень эскалатора' => 1601]),
        );
    }

    public function test_an_article_no_longer_gets_an_artificial_buy_suffix(): void
    {
        $phrases = Plan::keywords($this->item(['brand_article' => 'OTHR9004SPB']));

        $this->assertNotEmpty($phrases);
        foreach ($phrases as $phrase) {
            $this->assertStringNotContainsString('купить', $phrase);
        }
    }
}
