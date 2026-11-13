<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\CatalogSupplierPriceImportService;
use PHPUnit\Framework\TestCase;

/**
 * 13.11.2026: в импорт истории закупочных цен загрузили выгрузку ручной синхронизации — колонки
 * съехали, в валюту встало «6 683,19», и синхронизация пула QuoteParts падала с 422 на всей пачке.
 */
class CatalogSupplierPriceCurrencyTest extends TestCase
{
    public function test_only_an_iso_code_is_a_currency(): void
    {
        $this->assertSame('USD', CatalogSupplierPriceImportService::currency(' usd '));
        $this->assertSame('CNY', CatalogSupplierPriceImportService::currency('CNY'));
        $this->assertNull(CatalogSupplierPriceImportService::currency('6 683,19'));
        $this->assertNull(CatalogSupplierPriceImportService::currency('руб.'));
        $this->assertNull(CatalogSupplierPriceImportService::currency(null));
    }
}
