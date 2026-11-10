<?php

namespace Tests\Unit\Requests;

use App\Livewire\Requests\Items\AddItemForm;
use PHPUnit\Framework\TestCase;

class AddItemFormSkuTest extends TestCase
{
    public function test_sku_typed_any_common_way_is_normalized(): void
    {
        $this->assertSame('M06374', AddItemForm::normalizeSku('M06374'));
        $this->assertSame('M06374', AddItemForm::normalizeSku(' м06374 '));   // кириллическая «м»
        $this->assertSame('M06374', AddItemForm::normalizeSku('М 06374'));    // кириллическая «М» и пробел
        $this->assertSame('M06374', AddItemForm::normalizeSku('06374'));      // одни цифры
    }

    public function test_not_an_m_article_gives_null(): void
    {
        $this->assertNull(AddItemForm::normalizeSku(''));
        $this->assertNull(AddItemForm::normalizeSku('TLHIB-8B'));
        $this->assertNull(AddItemForm::normalizeSku('M12'));
    }
}
