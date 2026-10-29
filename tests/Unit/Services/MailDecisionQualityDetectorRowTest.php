<?php

namespace Tests\Unit\Services;

use App\Services\Analytics\MailDecisionQualityService;
use PHPUnit\Framework\TestCase;

/** Доля отклонённых на /dashboard/mail-quality — только несогласие менеджера (29.10.2026). */
class MailDecisionQualityDetectorRowTest extends TestCase
{
    private function row(array $over): object
    {
        return (object) array_merge([
            'detector_type' => 'inbound_postponed', 'total' => 0, 'auto_applied' => 0, 'dismissed' => 0,
            'not_applicable' => 0, 'confirmed' => 0, 'overridden' => 0,
        ], $over);
    }

    public function test_a_status_already_further_is_not_a_rejection(): void
    {
        // «Клиент: отложил» за 30 дней: 6 применено, 14 не применились — счёт уже выставлен.
        $d = MailDecisionQualityService::detectorRow($this->row(['total' => 20, 'auto_applied' => 6, 'not_applicable' => 14]));

        $this->assertSame(0.0, $d['dismiss_rate']);
        $this->assertSame(14, $d['not_applicable']);
        $this->assertSame('Клиент: отложил', $d['label']);
    }

    public function test_the_rate_counts_manager_dismissals_and_overrides(): void
    {
        $d = MailDecisionQualityService::detectorRow($this->row([
            'detector_type' => 'inbound_possible_new_request', 'total' => 160, 'confirmed' => 1, 'dismissed' => 27, 'not_applicable' => 3, 'overridden' => 2,
        ]));

        $this->assertSame(round(29 / 30, 3), $d['dismiss_rate']);
    }

    public function test_no_decision_judged_gives_no_rate(): void
    {
        $d = MailDecisionQualityService::detectorRow($this->row(['detector_type' => 'inbound_unclear', 'total' => 1536, 'not_applicable' => 0]));

        $this->assertNull($d['dismiss_rate']);
    }
}
