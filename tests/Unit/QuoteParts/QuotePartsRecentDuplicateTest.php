<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use PHPUnit\Framework\TestCase;

/** Повтор запроса через QuoteParts: тот же поставщик, все те же позиции (MZ2026-00296, 10.11.2026). */
class QuotePartsRecentDuplicateTest extends TestCase
{
    private const SNAPSHOT = ['suppliers' => [
        ['external_id' => '00-001114', 'lines' => [['code' => 'T-N6JE48', 'status' => 'pending'], ['code' => 'M04990', 'status' => 'pending']]],
        ['external_id' => '00-010427', 'lines' => [['code' => 'T-N6JE48', 'status' => 'pending']]],
    ]];

    public function test_same_supplier_and_items_already_asked_is_a_repeat(): void
    {
        $this->assertTrue(QuotePartsRfqService::coversSupplier(self::SNAPSHOT, '00-001114', ['T-N6JE48']));
        $this->assertTrue(QuotePartsRfqService::coversSupplier(self::SNAPSHOT, '00-001114', ['M04990', 'T-N6JE48']));
    }

    public function test_a_new_item_or_another_supplier_is_not_a_repeat(): void
    {
        $this->assertFalse(QuotePartsRfqService::coversSupplier(self::SNAPSHOT, '00-001114', ['T-N6JE48', 'M00129']));
        $this->assertFalse(QuotePartsRfqService::coversSupplier(self::SNAPSHOT, '00-010427', ['M04990']));
        $this->assertFalse(QuotePartsRfqService::coversSupplier(self::SNAPSHOT, '00-000003', ['T-N6JE48']));
    }
}
