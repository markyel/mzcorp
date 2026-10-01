<?php

namespace Tests\Unit\Services\Marketing;

use App\Services\Marketing\WeeklyRoundupService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Обзор недели перепечатывают порталы, поэтому статья собирается так, чтобы
 * модель не могла ни выдумать позицию, ни написать цену: артикулы — только из
 * кандидатов раздела, цены и наличие — из каталога.
 */
class WeeklyRoundupAssembleTest extends TestCase
{
    private function service(): WeeklyRoundupService
    {
        return (new ReflectionClass(WeeklyRoundupService::class))->newInstanceWithoutConstructor();
    }

    private function item(string $sku, array $extra = []): array
    {
        return $extra + [
            'sku' => $sku, 'name' => 'Деталь '.$sku, 'brand' => 'Otis', 'part_type' => '', 'photo' => 'https://x/'.$sku.'.jpg',
            'url' => 'https://site/'.$sku, 'price' => 1000.0, 'old_price' => null, 'new_price' => null, 'pct' => 0, 'in_stock' => true,
        ];
    }

    private function candidates(): array
    {
        return [
            'from' => Carbon::parse('2026-09-24'), 'to' => Carbon::parse('2026-10-01'),
            'totals' => ['new' => 12, 'price' => 5, 'stock' => 0], 'stock_proxy' => true,
            'sections' => [
                'new' => [$this->item('M00001'), $this->item('M00002')],
                'price' => [$this->item('M00010', ['old_price' => 2000.0, 'new_price' => 1500.0, 'pct' => 25])],
                'stock' => [],
            ],
        ];
    }

    public function test_items_outside_the_candidates_are_dropped_and_sections_are_ordered(): void
    {
        $article = $this->service()->assemble([
            'title' => 'Заголовок', 'lead' => 'Вступление',
            'sections' => [
                ['kind' => 'price', 'heading' => 'Цены', 'intro' => '', 'items' => [['sku' => 'M00010', 'note' => 'n']]],
                ['kind' => 'new', 'heading' => 'Новое', 'intro' => '', 'items' => [
                    ['sku' => 'M00001', 'note' => 'ok'], ['sku' => 'M99999', 'note' => 'выдумка'], ['sku' => 'm00002', 'note' => ''],
                ]],
            ],
            'closing' => '',
        ], $this->candidates());

        $this->assertNotNull($article);
        $this->assertSame(['new', 'price'], array_column($article['sections'], 'kind'));
        $this->assertSame(['M00001', 'M00002'], array_column($article['sections'][0]['items'], 'sku'));
        $this->assertSame('https://x/M00001.jpg', $article['cover']);
    }

    public function test_a_single_section_is_not_a_roundup(): void
    {
        $article = $this->service()->assemble([
            'title' => 'Заголовок', 'lead' => 'Вступление',
            'sections' => [['kind' => 'new', 'heading' => 'Новое', 'items' => [['sku' => 'M00001']]]],
        ], $this->candidates());

        $this->assertNull($article);
    }

    public function test_a_note_that_restates_the_name_is_dropped(): void
    {
        $name = 'Дисковый тормоз BFK466-55 205VDC для лебедок WITTUR WSG';
        $this->assertTrue(WeeklyRoundupService::restatesName('Электромагнитный тормоз для лебедок WITTUR WSG.', $name));
        $this->assertTrue(WeeklyRoundupService::restatesName('Модуль фильтра частотного преобразователя V3F25.', 'Модуль фильтра частотного преобразователя V3F25'));
        // Добавляет узел — оставляем.
        $this->assertFalse(WeeklyRoundupService::restatesName('Сальник на редуктор главного привода.', 'Сальник на редуктор 160VAT'));
        $this->assertFalse(WeeklyRoundupService::restatesName('', $name));
    }

    public function test_filler_phrases_are_found(): void
    {
        $this->assertSame(['что может заинтересовать', 'может заинтересовать'],
            WeeklyRoundupService::fillerFound(['lead' => 'Снижены цены, что может заинтересовать снабженцев.']));
        $this->assertSame([], WeeklyRoundupService::fillerFound(['lead' => 'В каталоге появились контроллеры Prisma.']));
    }

    public function test_price_is_shown_only_for_price_drops(): void
    {
        $drop = $this->item('M00010', ['old_price' => 8461.0, 'new_price' => 1196.0, 'pct' => 86]);

        $this->assertSame("8\u{00A0}461\u{00A0}₽ → 1\u{00A0}196\u{00A0}₽ (−86%), есть на складе", WeeklyRoundupService::priceLine($drop, 'price'));
        $this->assertSame('есть на складе', WeeklyRoundupService::priceLine($this->item('M00001'), 'new'));
        $this->assertStringNotContainsString('₽', WeeklyRoundupService::toHtml([
            'lead' => 'L', 'closing' => '', 'sections' => [['kind' => 'new', 'heading' => 'H', 'intro' => '', 'items' => [$this->item('M00001', ['note' => ''])]]],
        ]));
    }
}
