<?php

namespace Tests\Unit\Services\Quotations;

use App\Enums\RequestStatus;
use App\Services\Quotations\PartialQuoteService;
use PHPUnit\Framework\TestCase;

/**
 * Правила отложенного КП — те, что заказчик задал 23.09.2026:
 * полное уходит сразу, как только оценены все отложенные позиции; частичное
 * дополнение — не чаще раза в два дня; две недели с первого частичного КП —
 * предел, дальше заявка остаётся менеджеру.
 */
class PartialQuoteRulesTest extends TestCase
{
    public function test_the_new_status_is_not_a_waiting_on_client_one(): void
    {
        // Ключевое: в «Частичном КП» долг наш, а не клиента. Попади этот
        // статус в «ждём клиента» — заявку закрыло бы автозакрытие по
        // молчанию, хотя дослать полное КП обязаны мы.
        $this->assertFalse(RequestStatus::PartiallyQuoted->isWaitingOnClient());
        $this->assertTrue(RequestStatus::Quoted->isWaitingOnClient());
    }

    public function test_the_status_stays_in_the_working_pool(): void
    {
        $this->assertTrue(RequestStatus::PartiallyQuoted->isOpenForAssignment());
        $this->assertTrue(RequestStatus::PartiallyQuoted->isVisibleToManager());
        $this->assertFalse(RequestStatus::PartiallyQuoted->isTerminal());
    }

    public function test_a_partial_quote_can_become_a_full_one(): void
    {
        $this->assertContains(
            RequestStatus::Quoted,
            RequestStatus::PartiallyQuoted->allowedTransitions(),
            'досылка полного КП переводит заявку в «КП отправлено»',
        );
        $this->assertContains(
            RequestStatus::PartiallyQuoted,
            RequestStatus::InProgress->allowedTransitions(),
            'менеджер выдаёт частичное КП прямо из работы',
        );
    }

    public function test_a_partial_quote_is_a_milestone_below_the_full_one(): void
    {
        $this->assertGreaterThan(
            RequestStatus::PartiallyQuoted->lifecycleOrder(),
            RequestStatus::Quoted->lifecycleOrder(),
        );
        $this->assertGreaterThan(0, RequestStatus::PartiallyQuoted->lifecycleOrder());
    }

    public function test_the_windows_are_the_agreed_ones(): void
    {
        $this->assertSame(14, PartialQuoteService::WINDOW_DAYS);
        $this->assertSame(2, PartialQuoteService::FOLLOWUP_DAYS);
    }
}
