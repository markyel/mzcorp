<?php

namespace Tests\Unit\QuoteParts;

use App\Models\QuotePartsOffer;
use Tests\TestCase;

/** «Игнорировать»: предложение не идёт ценой позиции — ни оригинал, ни принятое отклонение. */
class QuotePartsOfferIgnoredTest extends TestCase
{
    public function test_ignored_offer_does_not_count(): void
    {
        $this->assertTrue((new QuotePartsOffer(['kind' => 'original']))->countsAsItemPrice());
        $this->assertFalse((new QuotePartsOffer(['kind' => 'original', 'ignored_at' => '2026-10-21 12:00:00']))->countsAsItemPrice());
        $this->assertFalse((new QuotePartsOffer(['kind' => 'analog', 'review_status' => 'accepted', 'ignored_at' => '2026-10-21 12:00:00']))->countsAsItemPrice());
    }
}
