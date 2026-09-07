<?php

namespace Tests\Unit\Enums;

use App\Enums\DetectorType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DetectorType::requiresDocumentEvidence — какие вехи ставятся только после
 * разбора документа парсером (счёт / КП), а не по тексту письма.
 * Требование заказчика 2026-09-07 (кейс M-2026-14608).
 */
class DetectorTypeTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_requires_document_evidence(DetectorType $type, bool $expected): void
    {
        $this->assertSame($expected, $type->requiresDocumentEvidence());
    }

    /**
     * @return iterable<string, array{DetectorType, bool}>
     */
    public static function cases(): iterable
    {
        yield 'счёт' => [DetectorType::OutboundInvoice, true];
        yield 'КП полное' => [DetectorType::OutboundQuotationFull, true];
        yield 'КП частичное' => [DetectorType::OutboundQuotationPartial, true];
        yield 'уточнение — по письму' => [DetectorType::OutboundClarification, false];
        yield 'отказ — по письму' => [DetectorType::OutboundDeclined, false];
        yield 'входящее: запрос счёта' => [DetectorType::InboundInvoiceRequest, false];
        yield 'входящее: отказ' => [DetectorType::InboundDecline, false];
    }
}
