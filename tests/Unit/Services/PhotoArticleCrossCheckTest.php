<?php

namespace Tests\Unit\Services;

use App\Services\RequestItemParsingService;
use Tests\TestCase;

/** Артикул с фото — только при совпадении двух моделей (MZ2026-03100, 25.11.2026). */
class PhotoArticleCrossCheckTest extends TestCase
{
    public function test_an_article_both_models_read_is_kept(): void
    {
        $items = RequestItemParsingService::keepAgreedArticles(
            [['name' => 'Фотозавеса', 'article' => 'FCU0735FI10', 'image_index' => 0]],
            [['name' => 'Фотозавеса KONE', 'article' => 'fcu 0735-fi10', 'image_index' => 0]],
        );

        $this->assertSame('FCU0735FI10', $items[0]['article']);
        $this->assertArrayNotHasKey('note', $items[0]);
    }

    public function test_a_made_up_code_is_dropped_and_both_readings_go_to_the_note(): void
    {
        $items = RequestItemParsingService::keepAgreedArticles(
            [['name' => 'Фотозавеса', 'article' => 'FCU0735FT10', 'image_index' => 0]],
            [['name' => 'Фотозавеса', 'article' => 'KM8534910', 'image_index' => 0]],
            'Подскажите наличие фотозавес с блоком питания',
        );

        $this->assertNull($items[0]['article']);
        $this->assertStringContainsString('«FCU0735FT10»', $items[0]['note']);
        $this->assertStringContainsString('«KM8534910»', $items[0]['note']);
    }

    public function test_a_code_from_the_letter_needs_no_second_reading(): void
    {
        $items = RequestItemParsingService::keepAgreedArticles(
            [['name' => 'Поручень', 'article' => 'KM50094097H01, M14761', 'image_index' => null]],
            [],
            "## ТЕКСТ\nПрошу счёт на поручень арт. KM50094097H01 (M14761)",
        );

        $this->assertSame('KM50094097H01, M14761', $items[0]['article']);
    }

    public function test_only_the_confirmed_part_of_several_codes_stays(): void
    {
        $items = RequestItemParsingService::keepAgreedArticles(
            [['name' => 'Плата', 'article' => 'KM713700G11, KM999999', 'image_index' => 1]],
            [['name' => 'Плата', 'article' => 'КМ713700G11', 'image_index' => 1]],
        );

        $this->assertSame('KM713700G11', $items[0]['article']);
        $this->assertStringContainsString('«KM999999»', $items[0]['note']);
    }

    public function test_a_longer_reading_of_the_same_code_counts_as_agreement(): void
    {
        $items = RequestItemParsingService::keepAgreedArticles(
            [['name' => 'Плата', 'article' => 'GBA21310', 'image_index' => 0]],
            [['name' => 'Плата', 'article' => 'GBA21310LF1', 'image_index' => 0]],
        );

        $this->assertSame('GBA21310', $items[0]['article']);
    }
}
