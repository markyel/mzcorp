<?php

namespace Tests\Unit\Services\Supplier;

use App\Services\Supplier\SupplierInquiryService;
use Tests\TestCase;

/**
 * Разбор маркера [RFQ-…] в теме письма. От него зависит детерминированный матч
 * ответа поставщика: без токена ответ уходит на запасные пути и может лечь
 * в чужой тред — кейс inquiry 5340, где поставщик переслал письмо по другому
 * запросу без In-Reply-To. Pure-метод, БД не нужна.
 */
class RfqTokenExtractionTest extends TestCase
{
    private function svc(): SupplierInquiryService
    {
        return app(SupplierInquiryService::class);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function subjects(): array
    {
        return [
            'запрос по заявке' => ['Price request — [M-2026-16280] / [367791] [RFQ-JZRZMH2]', 'JZRZMH2'],
            'русская тема' => ['Запрос расценки — [M-2026-15199] / [366855] [RFQ-ZHSKTBK]', 'ZHSKTBK'],
            'позиция-центричный из Снабжения' => ['Запрос расценки [RFQ-02O1GTS]', '02O1GTS'],
            'ответ поставщика с Re:' => ['Re: Price request — [M-2026-15808] [RFQ-8L7WSPI]', '8L7WSPI'],
            'пересылка чужого запроса' => ['Fwd: Price request — [M-2026-15808] / [367614] [RFQ-8L7WSPI]', '8L7WSPI'],
            'нижний регистр приводим к верхнему' => ['Re: [rfq-abc1234]', 'ABC1234'],
            'токен минимальной длины' => ['Запрос [RFQ-AB12]', 'AB12'],

            'без маркера' => ['Re: Запрос расценки — [M-2026-15199]', null],
            'пустая тема' => ['', null],
            'слишком короткий токен' => ['[RFQ-AB1]', null],
            'посторонний текст в скобках' => ['[RFQ-]', null],
            'похожее слово без скобок' => ['RFQ-ABC1234 без скобок', null],
        ];
    }

    /** @dataProvider subjects */
    public function test_extracts_token_from_subject(string $subject, ?string $expected): void
    {
        $this->assertSame($expected, $this->svc()->extractRfqToken($subject));
    }

    public function test_marker_round_trips_through_extraction(): void
    {
        $svc = $this->svc();
        $marker = $svc->rfqMarker('abc1234');
        $this->assertSame('[RFQ-ABC1234]', $marker);
        $this->assertSame('ABC1234', $svc->extractRfqToken('Запрос расценки — [M-2026-1] '.$marker));
    }

    public function test_null_subject_is_safe(): void
    {
        $this->assertNull($this->svc()->extractRfqToken(null));
    }
}
