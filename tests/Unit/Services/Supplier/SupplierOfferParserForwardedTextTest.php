<?php

namespace Tests\Unit\Services\Supplier;

use App\Models\EmailMessage;
use App\Services\Supplier\SupplierOfferParser;
use Tests\TestCase;

/**
 * Посредник-закупщик отвечает «См кп ниже» и ПЕРЕСЫЛАЕТ оффер реального
 * поставщика. Срез цитат не должен выбрасывать пересланное письмо, но
 * обычную историю переписки по-прежнему режем. Кейс M-2026-18330 (Fox через
 * UniSystem, «Euro 39,58»). Pure-метод, БД не нужна.
 */
class SupplierOfferParserForwardedTextTest extends TestCase
{
    private function relevant(string $body): string
    {
        $parser = app(SupplierOfferParser::class);
        $msg = new EmailMessage;
        $msg->id = 1;

        return (fn () => $this->relevantReplyText($body, $msg))->call($parser);
    }

    private const FOX_FORWARD = <<<'TXT'
Привет

См кп ниже
With best regards,
Alexander Rodenkov
UniSystem d.o.o.
+386 6 987 1454

Начало переадресованного письма:

От: Clara Anelli <clara@fox.it>
Дата: 7 октября 2026 г. в 17:06:34 GMT+3
Кому: info@unisystem.si
Тема: R: Price request — [M-2026-18330] / [369544] [RFQ-HVJSWBV]

Dear Alexander good afternoon,
Thank you so much for the request, here below our offer for

N°5 K4TAO1 Unit net price Euro 39,58

Delivery time | : | 5/7 days after payment received (EXW)
Offer validity | : | 30 days

Best Regards
Clara Anelli
FOX S.r.l.

Da: UniSystem Info <info@unisystem.si>
Inviato: lunedì 5 ottobre 2026 13:10
A: XF Srl Sales <sales@xf-online.it>
Oggetto: Price request — [M-2026-18330] / [369544] [RFQ-HVJSWBV]

Dear Clara

Please, quote
1) Overload limit switch K4TA (NO)
Code K4TAO1
- 5 pcs
TXT;

    public function test_forwarded_supplier_offer_is_kept_and_nested_rfq_is_cut(): void
    {
        $out = $this->relevant(self::FOX_FORWARD);

        $this->assertStringContainsString('См кп ниже', $out);
        $this->assertStringContainsString('[Пересланное письмо от Clara Anelli <clara@fox.it>]', $out);
        $this->assertStringContainsString('Unit net price Euro 39,58', $out);
        $this->assertStringContainsString('Offer validity', $out);
        // Вложенная история — наш же RFQ — срезана.
        $this->assertStringNotContainsString('Please, quote', $out);
        $this->assertStringNotContainsString('Oggetto:', $out);
    }

    /** Apple Mail: пересланное письмо целиком под «> » (inquiry 4727, 5116). */
    public function test_quoted_forward_apple_mail_style_is_parsed(): void
    {
        $body = "См ниже
With best regards,
Alexander Rodenkov
UniSystem d.o.o.

> Начало переадресованного письма:
> 
> Отправитель: Gloria Bergamaschi <gloria.bergamaschi@hydroniclift.it>
> Тема: R: Request [M-2026-15199] / [366855] [RFQ-ZHSKTBK]
> Дата: 9 сентября 2026 г. в 16:54:41 GMT+2
> Кому: UniSystem Alex <alex@unisystem.si>
> 
> Dear Alexander,
> please find our offer: valve VL 1/2 — 85,00 EUR net each, delivery 2 weeks.
> 
> Best regards
> Gloria
> 
>> Il 09/09/2026 10:12, UniSystem Alex ha scritto:
>> Please quote valve VL 1/2 - 2 pcs
";
        $out = $this->relevant($body);

        $this->assertStringContainsString('[Пересланное письмо от Gloria Bergamaschi', $out);
        $this->assertStringContainsString('85,00 EUR', $out);
        $this->assertStringNotContainsString('Please quote valve', $out);
    }

    public function test_forwarded_own_letter_is_not_included(): void
    {
        $body = "См ниже\n\n-------- Пересылаемое сообщение --------\nОт: Андрей Васюхно <andrey.vasukhno@myzip.ru>\nТема: Price request — [M-2026-18330]\n\nDear Clara, please quote 1) K4TA 5 pcs, price 39,58\n";
        $out = $this->relevant($body);

        $this->assertSame('См ниже', $out);
        $this->assertStringNotContainsString('39,58', $out);
    }

    public function test_substantive_reply_without_pointer_keeps_only_own_text(): void
    {
        $own = str_repeat('Мы проверили позицию, аналог есть под заказ, срок четыре недели, уточняем условия. ', 6);
        $body = $own."\n\nОт: Clara Anelli <clara@fox.it>\nТема: R: Price request\n\nUnit net price Euro 39,58\n";
        $out = $this->relevant($body);

        $this->assertStringNotContainsString('39,58', $out);
        $this->assertStringContainsString('аналог есть под заказ', $out);
    }

    public function test_plain_quote_without_letter_header_is_still_cut(): void
    {
        $body = "Ок, посмотрим и вернёмся с ответом в понедельник\n\n> 05.10.2026 13:10, Андрей пишет:\n> Unit net price Euro 39,58\n";
        $out = $this->relevant($body);

        $this->assertStringNotContainsString('39,58', $out);
    }
}
