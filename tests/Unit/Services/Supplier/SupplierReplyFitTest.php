<?php

namespace Tests\Unit\Services\Supplier;

use App\Models\EmailMessage;
use App\Models\SupplierInquiry;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Фолбэк «последний запрос этому поставщику»: письмо без треда, токена и номера
 * заявки цепляется к запросу по заявке, только если похоже на ответ на него.
 * Темы — из разбора 13.10.2026 (M-2026-10852 и ещё 39 писем после 29.09).
 */
class SupplierReplyFitTest extends TestCase
{
    private static function inquiry(string $subject, string $created = '2026-08-12 11:40:10'): SupplierInquiry
    {
        $i = new SupplierInquiry(['subject' => $subject]);
        $i->created_at = Carbon::parse($created);

        return $i;
    }

    private static function letter(string $subject, string $sent, string $body = ''): EmailMessage
    {
        return new EmailMessage(['subject' => $subject, 'sent_at' => Carbon::parse($sent), 'body_plain' => $body]);
    }

    /** @return array<string, array{string, string, string, string, bool}> */
    public static function cases(): array
    {
        $rfq = 'Price request — [M-2026-10852]';

        return [
            'поставка OSS до запроса' => [$rfq, 'Next LS delivery', '2026-08-12 11:38:05', 'See attached list for next LS delivery, pls check it carefully.', false],
            'ответ в переписке о поставке' => [$rfq, 'Re: Next LS delivery', '2026-08-13 04:05:11', 'Have you get our delivery list yesterday?', false],
            'новая тема о поставке без цены' => [$rfq, 'Next LS delivery for SKG handrail', '2026-08-18 11:13:57', 'See attached list for next LS delivery, pls check it carefully.', false],
            'ответ по заказу ES' => ['Price request — [M-2026-16627] / [368114]', 'Re: Re: Order ES 21.09.2026 20260921-2', '2026-10-01 10:00:00', 'Please check the order', false],
            'ответ по счёту' => ['Запрос расценки — [M-2026-13429] / [3653]', 'Re: Счет на оплату № 2241 от 28 августа 2026 г.pdf', '2026-09-01 10:00:00', 'Оплатили', false],
            'КП на запрос с новой темой' => ['Запрос расценки — [M-2026-18149] / [3693]', 'КП ООО "КЕВ-РУС" на поставку пульта управления.', '2026-09-30 10:00:00', 'Направляем коммерческое предложение', true],
            'ответ с той же темой' => ['000348965', 'Re: Re: 000348965', '2026-10-05 10:00:00', 'ok', true],
            'номер КП из темы запроса' => ['Price request — [M-2026-17717] / [368974]', 'Re: question abt M03656 (368974)', '2026-09-25 10:00:00', 'see below', true],
            'цена, но через два месяца' => [$rfq, 'Price list', '2026-10-20 10:00:00', 'new prices attached', false],
            'короткая тема «Test»' => ['Price request — [M-2026-18402] / [369616]', 'Test', '2026-09-30 10:00:00', 'test', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_fallback_attaches_only_replies(string $inquirySubject, string $subject, string $sent, string $body, bool $expected): void
    {
        $created = $inquirySubject === 'Price request — [M-2026-10852]' ? '2026-08-12 11:40:10' : '2026-09-20 10:00:00';

        $this->assertSame($expected, SupplierInquiryService::looksLikeReplyTo(self::inquiry($inquirySubject, $created), self::letter($subject, $sent, $body)));
    }
}
