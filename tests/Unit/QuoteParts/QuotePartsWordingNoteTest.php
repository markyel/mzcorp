<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use PHPUnit\Framework\TestCase;

/** Правка английского названия каталожной позиции уходит поставщику QuoteParts примечанием. */
class QuotePartsWordingNoteTest extends TestCase
{
    public function test_edited_wording_different_from_catalog_goes_as_note(): void
    {
        $this->assertSame('Our wording: Indication PCB TLHIB-8B red light',
            QuotePartsRfqService::wordingNote(' Indication PCB TLHIB-8B red light ', 'Indication board TLHIB-8B'));
    }

    public function test_same_as_catalog_up_to_case_and_spaces_gives_no_note(): void
    {
        $this->assertNull(QuotePartsRfqService::wordingNote('indication  board TLHIB-8B', 'Indication board TLHIB-8B'));
    }

    public function test_empty_or_cyrillic_wording_gives_no_note(): void
    {
        $this->assertNull(QuotePartsRfqService::wordingNote('', 'Board'));
        $this->assertNull(QuotePartsRfqService::wordingNote(null, 'Board'));
        $this->assertNull(QuotePartsRfqService::wordingNote('Плата индикации', 'Board'));
    }
}
