<?php

namespace Tests\Unit\Models;

use App\Models\CatalogItem;
use PHPUnit\Framework\TestCase;

/**
 * Артикул производителя для внешних сервисов (IQOT, письма поставщикам): без наших пояснений с
 * размером и без значений, которые сами размер или спецификация.
 */
class CatalogItemExternalArticleTest extends TestCase
{
    public function test_remarks_with_a_size_are_cut_off(): void
    {
        $this->assertSame('0411С.23.00.300', CatalogItem::externalArticle('0411С.23.00.300 (16 мм)'));
        $this->assertSame('OX-847W', CatalogItem::externalArticle('OX-847W (for guide rail 16 mm)'));
    }

    public function test_a_size_or_a_spec_is_not_an_article(): void
    {
        $this->assertNull(CatalogItem::externalArticle('PDC 300T d=10,0 mm,  9х21F-IWRC'));
        $this->assertNull(CatalogItem::externalArticle('16 мм'));
        $this->assertNull(CatalogItem::externalArticle('600x400'));
        $this->assertNull(CatalogItem::externalArticle('   '));
    }

    public function test_real_part_numbers_stay(): void
    {
        foreach (['KM247024', 'PC-04L=600', '6 65-03-00061', '10128505', 'AEN04C049*A-"12"', 'Z3K 236-02zr-M16-2406-6', '4Κ91-03-02000', '3RH1921-2EE02'] as $pn) {
            $this->assertSame($pn, CatalogItem::externalArticle($pn), $pn);
        }
    }

    public function test_the_oem_list_skips_sizes_and_our_codes(): void
    {
        $c = new CatalogItem(['sku' => 'M16101', 'brand_article' => 'OX-847W (for guide rail 16 mm)', 'articles' => ['OX-847W (for guide rail 16 mm)', 'YA136B736G15', 'M16101', 'PAA237E1', null]]);
        $this->assertSame('OX-847W', $c->oemForExternal());
        $this->assertSame(['OX-847W', 'YA136B736G15', 'PAA237E1'], $c->oemsForExternal());

        $rope = new CatalogItem(['sku' => 'M23008', 'brand_article' => 'PDC 300T d=10,0 mm,  9х21F-IWRC', 'articles' => ['PDC 300T d=10,0 mm,  9х21F-IWRC']]);
        $this->assertNull($rope->oemForExternal());
        $this->assertSame([], $rope->oemsForExternal());
    }
}
