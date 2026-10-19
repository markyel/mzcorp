<?php

namespace Tests\Unit\Procurement;

use App\Models\QuotePartsOffer;
use App\Services\Procurement\PriceExportService;
use Tests\TestCase;

/** Принятое отклонение уходит в выгрузку 1С с примечанием: что предложено вместо оригинала. */
class PriceExportDeviationNoteTest extends TestCase
{
    public function test_note_says_what_was_offered(): void
    {
        $o = new QuotePartsOffer(['kind' => 'analog', 'brand' => 'Fermator', 'part_number' => 'FR-60', 'note' => 'подходит по размерам']);

        $this->assertSame('аналог (не оригинал): Fermator FR-60 — подходит по размерам', PriceExportService::deviationNote($o));
    }
}
