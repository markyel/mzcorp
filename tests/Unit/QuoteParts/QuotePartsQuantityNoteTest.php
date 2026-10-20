<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use PHPUnit\Framework\TestCase;

/** Поле «Кол-во» рассылки — свободный текст: в QuoteParts число идёт количеством, остальное и длина — примечанием. */
class QuotePartsQuantityNoteTest extends TestCase
{
    public function test_typed_length_goes_as_note_quantity_from_request(): void
    {
        $this->assertSame(['quantity' => 1.0, 'note' => 'Qty: 21,710 mm'], QuotePartsRfqService::quantityAndNote('21,710 mm', 1.0, 21.71, 'м'));
    }

    public function test_plain_number_is_the_quantity(): void
    {
        $this->assertSame(['quantity' => 4.0, 'note' => null], QuotePartsRfqService::quantityAndNote('4', 1.0));
        $this->assertSame(['quantity' => 2.5, 'note' => null], QuotePartsRfqService::quantityAndNote('2,5 шт.', 1.0));
        $this->assertSame(['quantity' => 3.0, 'note' => null], QuotePartsRfqService::quantityAndNote('3 pcs', 1.0));
    }

    public function test_length_from_the_request_when_nothing_typed(): void
    {
        $this->assertSame(['quantity' => 1.0, 'note' => 'Length: 21.71 m'], QuotePartsRfqService::quantityAndNote('', 1.0, 21.71, 'м'));
        $this->assertSame(['quantity' => 2.0, 'note' => 'Length: 43.56 m'], QuotePartsRfqService::quantityAndNote('2', 1.0, 43.56, 'м'));
    }

    public function test_nothing_typed_no_length(): void
    {
        $this->assertSame(['quantity' => 3.0, 'note' => null], QuotePartsRfqService::quantityAndNote(null, 3.0));
        $this->assertSame(['quantity' => 1.0, 'note' => null], QuotePartsRfqService::quantityAndNote('', 0.0));
    }
}
