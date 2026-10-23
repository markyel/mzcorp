<?php

namespace Tests\Unit\Supplier;

use App\Models\CatalogItem;
use App\Models\RequestItem;
use App\Services\Supplier\SupplierDispatchService;
use Tests\TestCase;

/** Артикул позиции в письме поставщику: сматчена с каталогом — OEM из нашей базы, иначе артикул клиента. */
class SupplierDispatchItemOemTest extends TestCase
{
    private function item(?string $parsed, ?CatalogItem $catalog): RequestItem
    {
        $it = new RequestItem(['parsed_article' => $parsed]);
        $it->id = 1;
        $it->catalog_item_id = $catalog ? 4786 : null;
        $it->setRelation('catalogItem', $catalog);

        return $it;
    }

    public function test_matched_item_takes_catalog_oem(): void
    {
        $svc = app(SupplierDispatchService::class);
        $ci = new CatalogItem(['sku' => 'M09566', 'brand_article' => '13VTR-BRG-01', 'articles' => []]);

        $this->assertSame('13VTR-BRG-01', $svc->itemOem($this->item('LW-0027395', $ci)));
    }

    public function test_client_article_when_catalog_has_no_oem(): void
    {
        $svc = app(SupplierDispatchService::class);

        $this->assertSame('LW-0027395', $svc->itemOem($this->item('LW-0027395', new CatalogItem(['sku' => 'M09566', 'brand_article' => null, 'articles' => []]))));
        $this->assertSame('LW-0027395', $svc->itemOem($this->item('LW-0027395', null)));
    }

    public function test_manager_edit_wins(): void
    {
        $svc = app(SupplierDispatchService::class);
        $ci = new CatalogItem(['sku' => 'M09566', 'brand_article' => '13VTR-BRG-01', 'articles' => []]);

        $this->assertSame('ABC-1', $svc->itemOem($this->item('LW-0027395', $ci), [1 => 'ABC-1']));
    }
}
