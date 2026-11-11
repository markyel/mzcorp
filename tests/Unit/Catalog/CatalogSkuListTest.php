<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\CatalogSkuList;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Список M-артикулов → позиции каталога («Снабжение» и раздел «QuoteParts»). БД — SQLite в памяти. */
class CatalogSkuListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.sku_list_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'sku_list_test',
        ]);
        Schema::create('catalog_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('catalog_items')->insert([
            ['id' => 1, 'sku' => 'M05333', 'is_active' => true],
            ['id' => 2, 'sku' => 'M26889', 'is_active' => false],
            ['id' => 3, 'sku' => 'M26889', 'is_active' => true],
        ]);
    }

    public function test_typos_are_forgiven_and_the_active_item_wins(): void
    {
        $r = CatalogSkuList::resolve("М5333\nm-26889, M 99999 и ещё текст M05333");

        $this->assertSame(['M05333', 'M26889', 'M99999'], $r['wanted']);
        $this->assertSame(1, $r['found']['M05333']->id);
        $this->assertSame(3, $r['found']['M26889']->id, 'снятая позиция — только если активной нет');
        $this->assertSame(['M99999'], $r['missing']);
    }

    public function test_text_without_articles_finds_nothing(): void
    {
        $this->assertSame(['wanted' => [], 'missing' => []], array_diff_key(CatalogSkuList::resolve('ролик двери Otis'), ['found' => 0]));
    }
}
