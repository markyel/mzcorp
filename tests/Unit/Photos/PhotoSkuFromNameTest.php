<?php

namespace Tests\Unit\Photos;

use App\Services\Photos\PhotoBatchService;
use Tests\TestCase;

/** M-артикул из имени фото: ровно артикул — фото позиции (в каталог), с хвостом — доп. фото (только архив). */
class PhotoSkuFromNameTest extends TestCase
{
    public function test_exact_name_is_the_items_photo(): void
    {
        $this->assertSame(['M12345', true], PhotoBatchService::skuFromName('M12345.jpg'));
        $this->assertSame(['M03098', true], PhotoBatchService::skuFromName('m03098.PNG'));
        $this->assertSame(['M25391', true], PhotoBatchService::skuFromName('М25391.jpeg'), 'кириллическая М');
    }

    public function test_name_with_a_tail_is_an_extra_photo(): void
    {
        $this->assertSame(['M12345', false], PhotoBatchService::skuFromName('M12345_2.jpg'));
        $this->assertSame(['M12345', false], PhotoBatchService::skuFromName('M12345 (1).png'));
        $this->assertSame(['M12345', false], PhotoBatchService::skuFromName('M12345-вид сбоку.jpg'));
    }

    public function test_other_names_have_no_sku(): void
    {
        $this->assertSame([null, false], PhotoBatchService::skuFromName('IMG_2041.jpg'));
        $this->assertSame([null, false], PhotoBatchService::skuFromName('M12.jpg'));
        $this->assertSame([null, false], PhotoBatchService::skuFromName('M123456789.jpg'));
    }
}
