<?php

namespace Tests\Unit\QuoteParts;

use App\Models\QuotePartsOffer;
use App\Services\QuoteParts\PriceActualizationService;
use Tests\TestCase;

/**
 * «+ В выгрузку» без выбранного поставщика: выбирается самое дешёвое оригинальное предложение,
 * нет оригиналов — самое дешёвое из всех; «Игнорировать» не выбирается.
 */
class PriceActualizationCheapestTest extends TestCase
{
    private static function offer(int $id, float $price, string $kind = 'original', bool $ignored = false): QuotePartsOffer
    {
        $o = new QuotePartsOffer;
        $o->forceFill(['id' => $id, 'price' => $price, 'currency' => 'RUB', 'kind' => $kind, 'supplier_key' => 'org:'.$id, 'ignored_at' => $ignored ? now() : null]);

        return $o;
    }

    public function test_the_cheapest_original_beats_a_cheaper_copy(): void
    {
        // QP-Q99XBE: копия 246 ₽ дешевле, но выбирается оригинал OSS 295 ₽, не YuanQi 492 ₽.
        $pick = PriceActualizationService::cheapest(collect([self::offer(1, 246, 'copy'), self::offer(2, 492), self::offer(3, 295)]));

        $this->assertSame(3, $pick->id);
    }

    public function test_without_originals_the_cheapest_of_all(): void
    {
        $pick = PriceActualizationService::cheapest(collect([self::offer(1, 300, 'analog'), self::offer(2, 250, 'copy')]));

        $this->assertSame(2, $pick->id);
    }

    public function test_ignored_offers_are_not_picked(): void
    {
        $this->assertSame(2, PriceActualizationService::cheapest(collect([self::offer(1, 100, 'original', true), self::offer(2, 200)]))->id);
        $this->assertNull(PriceActualizationService::cheapest(collect([self::offer(1, 100, 'original', true)])));
    }
}
