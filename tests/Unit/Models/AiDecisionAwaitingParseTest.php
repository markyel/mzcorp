<?php

namespace Tests\Unit\Models;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use App\Models\AiDecision;
use PHPUnit\Framework\TestCase;

/**
 * AiDecision::isAwaitingDocumentParse — плашка «AI: отправлено КП/счёт»
 * прячется от менеджера, пока парсер разбирает вложение (M-2026-14815:
 * менеджеры подтверждали вручную за 2–10 с, раньше автоматики). Без БД.
 */
class AiDecisionAwaitingParseTest extends TestCase
{
    private function decision(AiDecisionStatus $status, array $payload): AiDecision
    {
        $d = new AiDecision();
        $d->setRawAttributes([
            'detector_type' => DetectorType::OutboundQuotationFull->value,
            'status' => $status->value,
            'payload' => json_encode($payload),
        ], sync: true);

        return $d;
    }

    public function test_suggested_with_future_deadline_is_awaiting(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:36:20+03:00');
        $d = $this->decision(AiDecisionStatus::Suggested, [
            AiDecision::PAYLOAD_AWAITING_PARSE_UNTIL => '2026-09-07T12:51:14+03:00',
        ]);

        $this->assertTrue($d->isAwaitingDocumentParse($now));
    }

    public function test_expired_deadline_is_not_awaiting(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 13:00:00+03:00');
        $d = $this->decision(AiDecisionStatus::Suggested, [
            AiDecision::PAYLOAD_AWAITING_PARSE_UNTIL => '2026-09-07T12:51:14+03:00',
        ]);

        $this->assertFalse($d->isAwaitingDocumentParse($now));
    }

    public function test_without_key_is_not_awaiting(): void
    {
        $d = $this->decision(AiDecisionStatus::Suggested, ['signals' => []]);

        $this->assertFalse($d->isAwaitingDocumentParse(new \DateTimeImmutable()));
    }

    public function test_applied_decision_is_never_awaiting(): void
    {
        $d = $this->decision(AiDecisionStatus::AutoApplied, [
            AiDecision::PAYLOAD_AWAITING_PARSE_UNTIL => '2999-01-01T00:00:00+03:00',
        ]);

        $this->assertFalse($d->isAwaitingDocumentParse(new \DateTimeImmutable()));
    }

    public function test_garbage_deadline_is_not_awaiting(): void
    {
        $d = $this->decision(AiDecisionStatus::Suggested, [
            AiDecision::PAYLOAD_AWAITING_PARSE_UNTIL => 'not-a-date',
        ]);

        $this->assertFalse($d->isAwaitingDocumentParse(new \DateTimeImmutable()));
    }
}
