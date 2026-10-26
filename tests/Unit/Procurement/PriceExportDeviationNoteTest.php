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

    public function test_offer_comment_says_everything_important(): void
    {
        $o = new QuotePartsOffer(['kind' => 'analog', 'brand' => 'Fermator', 'part_number' => 'FR-60', 'note' => 'подходит по размерам',
            'tiers' => [['min_qty' => 50, 'price' => 48.5], ['min_qty' => 10, 'price' => 52]], 'lead_time_days' => 30, 'valid_until' => '2026-12-31']);

        $this->assertSame('аналог (не оригинал): Fermator FR-60 — подходит по размерам; мин. партия 10; цена по партиям: от 10 — 52; от 50 — 48,5; срок поставки 30 дн.',
            PriceExportService::offerComment($o));
    }

    public function test_plain_original_offer_has_only_its_terms(): void
    {
        $this->assertNull(PriceExportService::offerComment(new QuotePartsOffer(['kind' => 'original', 'tiers' => [['min_qty' => 1, 'price' => 3]]])));
        $this->assertSame('срок поставки 7 дн.; уточнять наличие', PriceExportService::offerComment(new QuotePartsOffer(['tiers' => [['min_qty' => 1, 'price' => 3]], 'lead_time_days' => 7, 'note' => 'уточнять наличие'])));
    }

    public function test_an_agents_price_names_the_real_supplier_first(): void
    {
        // UniSystem (агент, контрагент в 1С) — цена Sicor на замену ELT0206 + опора SUP0147.
        $o = new QuotePartsOffer(['kind' => 'original', 'origin_supplier' => 'Sicor Italy', 'tiers' => [['min_qty' => 1, 'price' => 329]],
            'lead_time_days' => 3, 'note' => 'ELT0206 €312 + Support SUP0147 €17']);

        $this->assertSame('поставщик Sicor Italy; срок поставки 3 дн.; ELT0206 €312 + Support SUP0147 €17', PriceExportService::offerComment($o));
    }

    public function test_mail_comment(): void
    {
        $this->assertSame('KM601 pulley: USD 52/pc, MOQ 10', PriceExportService::mailComment('30 days', "KM601 pulley:\n USD 52/pc, MOQ 10"));
        $this->assertNull(PriceExportService::mailComment(null, ''));
        // Срок из самого фрагмента письма не повторяем отдельной подписью.
        $this->assertSame('GAA26800KF1 оригинал 672 долл по шт, срок подготовки 3-5 дней',
            PriceExportService::mailComment('срок подготовки 3-5 дней', 'GAA26800KF1 оригинал 672 долл по шт, срок подготовки 3-5 дней'));
    }
}
