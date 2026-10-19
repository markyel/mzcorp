<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use PHPUnit\Framework\TestCase;

/** Номера заявки в теме запроса QuoteParts: «[M-2026-19835] / [370918] [QP-…]». */
class QuotePartsSubjectRefsTest extends TestCase
{
    public function test_request_code_and_1c_number(): void
    {
        $this->assertSame(['M-2026-19835', '370918'], QuotePartsRfqService::subjectRefs('M-2026-19835', ' 370918 '));
    }

    public function test_without_request_nothing(): void
    {
        $this->assertSame([], QuotePartsRfqService::subjectRefs(null, null));
    }

    public function test_junk_1c_number_is_dropped_not_sent(): void
    {
        $this->assertSame(['M-2026-19835'], QuotePartsRfqService::subjectRefs('M-2026-19835', '365543)'));
        $this->assertSame(['M-2026-19835'], QuotePartsRfqService::subjectRefs('M-2026-19835', 'order@liftway.store'));
        $this->assertSame(['M-2026-19835'], QuotePartsRfqService::subjectRefs('M-2026-19835', 'M-2026-19835'));
        $this->assertSame(['M-2026-19835', 'НФ0-7791'], QuotePartsRfqService::subjectRefs('M-2026-19835', 'НФ0-7791'));
    }
}
