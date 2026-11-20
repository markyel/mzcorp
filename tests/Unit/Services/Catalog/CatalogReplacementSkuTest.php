<?php

namespace Tests\Unit\Services\Catalog;

use App\Services\Catalog\CatalogResolutionService;
use PHPUnit\Framework\TestCase;

/** Снятая карточка «(ЗАМЕНЕНО НА M05793) …» — автоматчинг ставит замену (разбор КП за 2 недели, 20.11.2026). */
class CatalogReplacementSkuTest extends TestCase
{
    public function test_the_replacement_sku_is_read_from_a_retired_card_name(): void
    {
        $this->assertSame('M05793', CatalogResolutionService::replacementSku('(ЗАМЕНЕНО НА M05793) Ролик концентрический D48 мм'));
        $this->assertSame('M34160', CatalogResolutionService::replacementSku('(ЗАМЕНЕН НА M34160) Частотный преобразователь CPI 15'));
        $this->assertSame('M08010', CatalogResolutionService::replacementSku('(ЗАМЕНЕНО НА M08010 или M33625) Плата DCPB Otis'));
        $this->assertSame('M01120', CatalogResolutionService::replacementSku('(Заменен на М01120) Энкодер'), 'кириллическая М');
    }

    public function test_a_card_that_is_itself_the_replacement_is_left_alone(): void
    {
        $this->assertNull(CatalogResolutionService::replacementSku('Замок ДШ аварийного открывания (S2-4Z, S2-3L) (замена B153ACJX03)'));
        $this->assertNull(CatalogResolutionService::replacementSku('Плата SCP-08  (замена для  платы SCP-04) реле 48Vdc'));
        $this->assertNull(CatalogResolutionService::replacementSku(null));
    }
}
