<?php

namespace Tests\Unit\Mail;

use App\Services\RequestItemParsingService;
use Tests\TestCase;

/** Все фото позиции из разбора: главное первым, ракурсы и замеры — следом (MZ2026-03414). */
class ItemPhotoIndexesTest extends TestCase
{
    public function test_indexes_map_to_attachments_with_the_main_photo_first(): void
    {
        $ids = [101, 102, 103, 104];
        $this->assertSame([103, [103, 101, 104]], RequestItemParsingService::photoAttachments(['image_index' => 2, 'image_indexes' => [0, 2, 3, 9, null]], $ids));
        $this->assertSame([101, [101]], RequestItemParsingService::photoAttachments(['image_index' => 0], $ids));
        $this->assertSame([null, []], RequestItemParsingService::photoAttachments(['image_index' => null, 'image_indexes' => []], $ids));
        $this->assertSame([102, [102, 104]], RequestItemParsingService::photoAttachments(['image_index' => null, 'image_indexes' => [1, 3]], $ids));
    }

    public function test_side_variants_are_recognised_as_one_part(): void
    {
        $base = \App\Services\Request\RequestItemPersister::withoutSide('Гребенка центральная для эскалатора Hyundai');
        $this->assertSame($base, \App\Services\Request\RequestItemPersister::withoutSide('Гребенка левая для эскалатора Hyundai'));
        $this->assertSame($base, \App\Services\Request\RequestItemPersister::withoutSide('Гребенка Правая для эскалатора Hyundai'));
        $this->assertNotSame(
            \App\Services\Request\RequestItemPersister::withoutSide('Табло индикации KONE'),
            \App\Services\Request\RequestItemPersister::withoutSide('Плата управления KONE LCE ADOe'),
        );
    }

    public function test_merged_items_keep_photos_of_both(): void
    {
        $this->assertSame([5, 6, 7], RequestItemParsingService::photoIds(['email_attachment_id' => 5, 'email_attachment_ids' => [5, 6]], ['email_attachment_id' => 7]));
        $this->assertSame([], RequestItemParsingService::photoIds(['email_attachment_id' => null]));
    }
}
