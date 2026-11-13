<?php

namespace Tests\Unit\Catalog;

use App\Models\CatalogManualPrice;
use App\Services\Catalog\CatalogImportService;
use App\Services\Catalog\CatalogManualSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 13.11.2026: ручная синхронизация (sync=1) переименовала M26691 в 1С, а название в каталоге
 * осталось старым — сервис писал только цены. Название из файла пишется и держится от отката
 * плановым импортом, как цена.
 */
class CatalogManualSyncNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.sync_name_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'sync_name_test',
        ]);
        Schema::create('catalog_manual_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('catalog_item_id');
            $t->string('sku');
            $t->string('name')->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->decimal('price_min', 12, 2)->nullable();
            $t->decimal('purchase_price', 12, 2)->nullable();
            $t->date('price_date')->nullable();
            $t->unsignedBigInteger('catalog_manual_sync_id')->nullable();
            $t->timestamp('hold_until');
            $t->timestamps();
        });
    }

    public function test_a_renamed_position_is_seen_as_changed(): void
    {
        $this->assertTrue(CatalogManualSyncService::nameDiffers(
            'Вентилятор крыши кабины,тип  ZFB-9B, 220V AC, 25W',
            'Вентилятор крыши кабины тип ZFB-9B, 220V AC, 25W (аналог)',
        ));
        $this->assertFalse(CatalogManualSyncService::nameDiffers('Поручень  7539FWNX', ' Поручень 7539FWNX '));
        $this->assertFalse(CatalogManualSyncService::nameDiffers('Поручень', ''), 'пустое название в файле — не переименование');
    }

    public function test_the_import_keeps_the_new_name_until_mylift_catches_up(): void
    {
        CatalogManualPrice::create([
            'catalog_item_id' => 1, 'sku' => 'M26691', 'name' => 'Вентилятор ZFB-9B (аналог)',
            'price' => 5000, 'hold_until' => now()->addDays(14),
        ]);
        $svc = app(CatalogImportService::class);

        $old = $svc->holdManualPrices([['sku' => 'M26691', 'name' => 'Вентилятор ZFB-9B', 'price' => '5000', 'is_price_actual' => true]]);
        $this->assertSame('Вентилятор ZFB-9B (аналог)', $old[0]['name']);
        $this->assertSame(1, CatalogManualPrice::count(), 'цена догнала, название нет — держим');

        $svc->holdManualPrices([['sku' => 'M26691', 'name' => 'Вентилятор ZFB-9B (аналог)', 'price' => '5000', 'is_price_actual' => true]]);
        $this->assertSame(0, CatalogManualPrice::count(), 'выгрузка догнала 1С — удержание снято');
    }
}
