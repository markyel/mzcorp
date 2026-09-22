<?php

namespace Tests\Unit\Models;

use App\Models\DirectPublishedAd;
use PHPUnit\Framework\TestCase;

/**
 * По этому сравнению конвейер решает, какие объявления надо привести к плану.
 * Ошибётся в сторону «совпадает» — фразы останутся старыми навсегда;
 * ошибётся в другую — каждый час будет дёргать Директ без нужды.
 */
class DirectPublishedAdKeywordsTest extends TestCase
{
    private function ad(array $keywords): DirectPublishedAd
    {
        $ad = new DirectPublishedAd;
        $ad->keywords = $keywords;

        return $ad;
    }

    public function test_the_same_set_in_another_order_is_the_same_set(): void
    {
        $ad = $this->ad(['xo-508', 'поручень эскалатора']);

        $this->assertFalse($ad->keywordsDifferFrom(['поручень эскалатора', 'XO-508']));
    }

    public function test_an_added_or_removed_phrase_is_a_difference(): void
    {
        $ad = $this->ad(['xo-508', 'xo-508 купить']);

        $this->assertTrue($ad->keywordsDifferFrom(['xo-508']), 'убрали искусственное «купить»');
        $this->assertTrue($ad->keywordsDifferFrom(['xo-508', 'xo-508 купить', 'гребенка эскалатора']));
    }

    public function test_an_ad_without_stored_phrases_needs_a_pass(): void
    {
        $ad = new DirectPublishedAd;

        $this->assertTrue($ad->keywordsDifferFrom(['xo-508']));
    }
}
