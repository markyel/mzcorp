<?php

namespace Tests\Unit\Services\Marketing;

use App\Services\Marketing\MarketingActService;
use Tests\TestCase;

/**
 * Акт по форме Приложения № 3: стоимость по п. 3.1–3.5 договора —
 * 160 000 руб. в месяц без НДС, НДС 22% сверху, неполный месяц — по дням.
 */
class MarketingActTest extends TestCase
{
    private function svc(): MarketingActService
    {
        return app(MarketingActService::class);
    }

    public function test_full_month_matches_the_contract_figures(): void
    {
        $a = $this->svc()->amounts('2026-10-01', '2026-10-31', 160000, 22);

        $this->assertTrue($a['full_month']);
        $this->assertSame(160000.0, $a['base']);
        $this->assertSame(35200.0, $a['vat']);
        $this->assertSame(195200.0, $a['total']);
    }

    public function test_partial_september_is_prorated_by_calendar_days(): void
    {
        // 17–30 сентября: 14 из 30 дней.
        $a = $this->svc()->amounts('2026-09-17', '2026-09-30', 160000, 22);

        $this->assertFalse($a['full_month']);
        $this->assertSame([['month' => '2026-09', 'days' => 14, 'of' => 30, 'base' => 74666.67]], $a['parts']);
        $this->assertSame(74666.67, $a['base']);
        $this->assertSame(16426.67, $a['vat']);
        $this->assertSame(91093.34, $a['total']);
    }

    public function test_period_across_months_counts_each_month_by_its_length(): void
    {
        $a = $this->svc()->amounts('2026-09-17', '2026-10-31', 160000, 22);

        $this->assertSame(234666.67, $a['base']);
        $this->assertCount(2, $a['parts']);
    }

    public function test_act_text_has_period_amounts_in_words_and_partial_month_basis(): void
    {
        $path = $this->svc()->renderData(
            ['contract_number' => '5', 'contract_date' => '«17» сентября 2026 г.', 'city' => 'Москва'],
            ['number' => '1', 'date' => '2026-09-30', 'from' => '2026-09-17', 'to' => '2026-09-30'],
        );
        $zip = new \ZipArchive;
        $zip->open($path);
        $text = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", (string) $zip->getFromName('word/document.xml'))), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('АКТ № 1 ОКАЗАННЫХ УСЛУГ', $text);
        $this->assertStringContainsString('г. Москва', $text);
        $this->assertStringContainsString('В период с «17» сентября 2026 г. по «30» сентября 2026 г.', $text);
        $this->assertStringContainsString('Договором № 5 от «17» сентября 2026 г.', $text);
        $this->assertStringContainsString('неполный календарный месяц (14 из 30 календарных дней', $text);
        $this->assertStringContainsString("74\u{00A0}666,67 руб. (Семьдесят четыре тысячи шестьсот шестьдесят шесть рублей 67 копеек)", $text);
        $this->assertStringContainsString('НДС 22%', $text);
    }
}
