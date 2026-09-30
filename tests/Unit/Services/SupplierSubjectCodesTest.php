<?php

namespace Tests\Unit\Services;

use App\Services\Supplier\SupplierInquiryService;
use PHPUnit\Framework\TestCase;

/**
 * Коды в теме письма поставщику: M-код заявки и номера документов без
 * ведущих нулей (29.09: «Re: 000367712» — КП 367712 по M-2026-16180).
 */
class SupplierSubjectCodesTest extends TestCase
{
    public function test_codes_from_subject(): void
    {
        $this->assertSame(['367712'], SupplierInquiryService::subjectCodes('Re: 000367712'));
        $this->assertSame(['M-2026-17545', '368825'], SupplierInquiryService::subjectCodes('Re: Price request— [M-2026-17545] / [368825] [RFQ-HBTQWQM]'));
        $this->assertSame([], SupplierInquiryService::subjectCodes('Re: Limit switch price'));
        // 11 цифр — номер детали, не документ.
        $this->assertSame([], SupplierInquiryService::subjectCodes('Re: 65100009237'));
    }
}
