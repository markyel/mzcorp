<?php

namespace Tests\Unit\Models;

use App\Models\CatalogItem;
use App\Models\IqotPosition;
use App\Services\Settings\SettingsService;
use Tests\TestCase;

/**
 * Приоритет источника «нашей цены» в IqotPosition::priceComparison().
 *
 * Изменение: сравниваем ТЕКУЩУЮ цену позиции (цена каталога), а не цену из
 * выданного КП. Если по позиции провели работу и цену снизили в каталоге,
 * отчёт IQOT должен это отражать. Порядок приоритета:
 *   1) цена каталога (catalog.price) — даже если is_price_actual=false;
 *   2) цена последнего проигранного КП (our_unit_price) — фолбэк, когда в
 *      каталоге цены нет вовсе.
 *
 * Модели живут в памяти (catalogItem проставляется через setRelation), БД не
 * нужна. SettingsService подменяем заглушкой → app_setting возвращает дефолт,
 * НДС = config('services.tax.vat_percent'). Офферы в ₽ — без FX.
 */
class IqotPositionPriceComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // app_setting(key, default) → default (без обращения к БД настроек).
        $settings = $this->createMock(SettingsService::class);
        $settings->method('get')->willReturnCallback(fn ($key, $default = null) => $default);
        $this->app->instance(SettingsService::class, $settings);
    }

    /**
     * @param  list<float>  $offerGrossRub  цены офферов, ₽ с НДС
     */
    private function position(
        ?float $catalogPrice,
        bool $isPriceActual,
        ?float $ourUnitPrice,
        ?string $quotationCode = null,
        array $offerGrossRub = [],
        ?float $priceMin = null,
    ): IqotPosition {
        $pos = new IqotPosition;
        $pos->our_unit_price = $ourUnitPrice;
        $pos->our_quotation_code = $quotationCode;
        $pos->report = ['all_offers' => array_map(fn ($p) => [
            'supplier' => ['name' => 'Поставщик'],
            'price_per_unit' => $p,
            'currency' => 'RUB',
            'price_includes_vat' => true,
        ], $offerGrossRub)];

        $cat = null;
        if ($catalogPrice !== null) {
            $cat = new CatalogItem;
            $cat->price = $catalogPrice;
            $cat->is_price_actual = $isPriceActual;
            $cat->price_min = $priceMin;
            $cat->lead_time_days = 7;
        }
        $pos->setRelation('catalogItem', $cat);

        return $pos;
    }

    private function ourRow(array $cmp): ?array
    {
        foreach ($cmp['rows'] as $row) {
            if ($row['is_ours']) {
                return $row;
            }
        }

        return null;
    }

    public function test_actual_catalog_price_wins_over_kp(): void
    {
        // По позиции снизили цену: каталог 100 (актуальна), КП было 200.
        $cmp = $this->position(100.0, true, 200.0, 'КП-1')->priceComparison();

        $this->assertSame('Наша цена (каталог)', $cmp['our_label']);
        $this->assertSame(100.0, $this->ourRow($cmp)['raw']);
    }

    public function test_nonactual_catalog_still_wins_over_kp(): void
    {
        // Каталог 100 (пометка «не актуальна») всё равно приоритетнее КП 200 —
        // это наша текущая цена; КП используем только если каталога нет вовсе.
        $cmp = $this->position(100.0, false, 200.0, 'КП-1')->priceComparison();

        $this->assertSame('Наша цена (каталог)', $cmp['our_label']);
        $this->assertSame(100.0, $this->ourRow($cmp)['raw']);
        $this->assertStringContainsString('цена не актуальна', (string) $this->ourRow($cmp)['notes']);
    }

    public function test_falls_back_to_kp_when_no_catalog_price(): void
    {
        $cmp = $this->position(null, true, 200.0, 'КП-1')->priceComparison();

        $this->assertSame('Наше КП КП-1', $cmp['our_label']);
        $this->assertSame(200.0, $this->ourRow($cmp)['raw']);
    }

    public function test_uses_nonactual_catalog_when_no_kp(): void
    {
        $cmp = $this->position(100.0, false, null)->priceComparison();

        $this->assertSame('Наша цена (каталог)', $cmp['our_label']);
        $this->assertSame(100.0, $this->ourRow($cmp)['raw']);
        $this->assertStringContainsString('цена не актуальна', (string) $this->ourRow($cmp)['notes']);
    }

    public function test_no_our_row_when_no_price_anywhere(): void
    {
        $cmp = $this->position(null, true, null, null, [150.0])->priceComparison();

        $this->assertNull($cmp['our_rank']);
        $this->assertNull($this->ourRow($cmp));
    }

    public function test_zero_catalog_price_is_unknown_not_free(): void
    {
        // Каталожный 0,00 (нет реальной цены) → не используем, фолбэк на КП.
        $cmp = $this->position(0.0, true, 200.0, 'КП-1')->priceComparison();

        $this->assertSame('Наше КП КП-1', $cmp['our_label']);
        $this->assertSame(200.0, $this->ourRow($cmp)['raw']);
    }

    public function test_lowered_catalog_price_improves_rank(): void
    {
        // Кейс пользователя: офферы 150 и 180 ₽; КП было 200 (заняло бы
        // последнее место), но текущая цена каталога 120 → 1-е место.
        $cmp = $this->position(120.0, true, 200.0, 'КП-1', [150.0, 180.0])->priceComparison();

        $this->assertSame(1, $cmp['our_rank']);
        $this->assertSame(3, $cmp['total']);
    }
}
