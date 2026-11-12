<?php

namespace Tests\Unit\QuoteParts;

use App\Models\CatalogItem;
use App\Models\Supplier;
use App\Services\Supplier\SupplierProcurementDispatchService;
use Tests\TestCase;

/** Отметка «всегда показывать M-артикул»: код перед названием позиции в письме внесистемному поставщику (без базы). */
class SupplierShowCodeTest extends TestCase
{
    public function test_the_code_goes_before_the_name_once(): void
    {
        $this->assertSame('M12345 Ролик башмака', CatalogItem::nameWithCode('Ролик башмака', 'M12345'));
        $this->assertSame('M12345 Ролик башмака', CatalogItem::nameWithCode('M12345 Ролик башмака', 'M12345'), 'already put by the «+» button');
        $this->assertSame('M12345 M123456 Ролик', CatalogItem::nameWithCode('M123456 Ролик', 'M12345'), 'a longer code is another code');
        $this->assertSame('Ролик', CatalogItem::nameWithCode('Ролик', null));
    }

    public function test_the_letter_shows_the_code_only_to_a_supplier_marked_for_it(): void
    {
        $items = collect([(new CatalogItem)->forceFill(['id' => 7, 'sku' => 'M12345', 'name' => 'Ролик башмака', 'name_en' => 'Shoe roller', 'brand' => 'Otis', 'brand_article' => 'GAA123'])]);
        $plain = (new Supplier)->forceFill(['id' => 1, 'email' => 'a@supplier.example', 'language' => 'en', 'quoteparts_show_code' => false]);
        $marked = (new Supplier)->forceFill(['id' => 2, 'email' => 'b@supplier.example', 'language' => 'en', 'quoteparts_show_code' => true]);
        $dispatcher = app(SupplierProcurementDispatchService::class);

        $hidden = $dispatcher->composeLetter($items, $plain, null);
        $shown = $dispatcher->composeLetter($items, $marked, null);

        $this->assertStringNotContainsString('M12345', $hidden['html'].$hidden['plain']);
        $this->assertStringContainsString('M12345 Shoe roller', $shown['html']);
        $this->assertStringContainsString('M12345 Shoe roller', $shown['plain']);
        $this->assertStringContainsString('GAA123', $shown['plain']);
    }
}
