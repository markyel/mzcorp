<?php

namespace Tests\Unit\AI;

use App\Console\Commands\AiDecisionsCloseStaleCommand as Cmd;
use App\Enums\DetectorType as T;
use App\Enums\RequestStatus as S;
use PHPUnit\Framework\TestCase;

class AiDecisionsCloseStaleTest extends TestCase
{
    public function test_request_already_in_target_or_further_in_the_funnel_is_stale(): void
    {
        $this->assertSame('уже в целевом', Cmd::staleReason(T::OutboundQuotationFull, S::Quoted));
        $this->assertSame('заявка дальше цели', Cmd::staleReason(T::OutboundQuotationFull, S::Invoiced));
        $this->assertSame('заявка дальше цели', Cmd::staleReason(T::InboundInvoiceRequest, S::Paid));
    }

    public function test_closed_or_missing_request_makes_any_suggestion_stale(): void
    {
        $this->assertSame('заявка закрыта', Cmd::staleReason(T::InboundUnclear, S::ClosedWon));
        $this->assertSame('нет заявки', Cmd::staleReason(T::OutboundInvoice, null));
    }

    public function test_request_before_target_stays(): void
    {
        $this->assertNull(Cmd::staleReason(T::InboundInvoiceRequest, S::Quoted));
        $this->assertNull(Cmd::staleReason(T::OutboundQuotationFull, S::InProgress));
        $this->assertNull(Cmd::staleReason(T::InboundUnclear, S::Quoted));
    }

    public function test_target_outside_the_funnel_is_not_compared_by_order(): void
    {
        // Отказ или «отложим» клиента после КП — актуальны, хотя Quoted «дальше» в воронке.
        $this->assertNull(Cmd::staleReason(T::InboundDecline, S::Quoted));
        $this->assertNull(Cmd::staleReason(T::InboundPostponed, S::Quoted));
        $this->assertNull(Cmd::staleReason(T::OutboundClarification, S::Quoted));
    }
}
