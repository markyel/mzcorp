<?php

namespace Tests\Unit\Supplier;

use App\Models\SupplierInquiry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Запрос цены или просто переписка (11.11.2026): темы — настоящие ветки раздела «Поставщики» из
 * ящиков снабжения. Запросы, отправленные снабженцем со своего ящика, видно по теме.
 */
class SupplierInquiryKindTest extends TestCase
{
    private static function thread(string $subject, array $attrs = []): SupplierInquiry
    {
        $i = new SupplierInquiry(['subject' => $subject] + $attrs);
        $i->setRelation('items', collect());

        return $i;
    }

    public static function priceRequests(): array
    {
        return [
            ['Запрос 000372849'], ['Re: Request 000372688'], ['[372884] запрос'], ['Request Thyssen COP M08334'],
            ['000373308'], ['Re: 000373308'], ['Re:  Request Pit cover safety switch 2NO M02350'],
            ['Re: Commercial Proposal Request(HZOIC LINDA)'], ['Fwd: Angebot Nr. 2604664 Request 372868 NEW LIFT'],
            ['Price request — [MZ2026-00339] / [372742]'], ['[372853] GAA177HR1'],
        ];
    }

    public static function correspondence(): array
    {
        return [
            ['Re:  Заказ для YuanQi от 19.10.2026 с доставкой mr Fu морской перевозкой'], ['Re: Order YuanQi 14.10.2026 RUPL261015-4031'],
            ['Re: Счета 377 и 378 Fwd: Заказ для КЛР-Л от 11.08.2026 (Мой Лифт)'], ['Счет 263387 от 09.11.2026'],
            ['Re: Re: Next DH delivery DH555-142'], ['Яндекс 360 для бизнеса: что доступно сейчас'], ['Вам купон на скидку'],
            ['Переписка с поставщиком'], ['Re: вопрос по пружинам'], ['RE: Реализация ЛСГ-МЛ 4 квартал 2026 г. ЧАСТЬ 3 - ВСЁ ОК'],
            ['Re: ЕСТЬ СЕРТИФИКАТ!!! В дороге YQ-ЦИ-28 вышло'], ['FH0126110002 shipment'], ['Расчет YuanQi отгрузка ноябрь 2026'],
            ['Re: Счет №105910 от 11.09.2026 г.'],
        ];
    }

    #[DataProvider('priceRequests')]
    public function test_a_price_request_is_shown(string $subject): void
    {
        $this->assertTrue(self::thread($subject)->isPriceRequestThread(), $subject);
    }

    #[DataProvider('correspondence')]
    public function test_correspondence_is_hidden(string $subject): void
    {
        $this->assertFalse(self::thread($subject)->isPriceRequestThread(), $subject);
    }

    public function test_sent_by_the_system_is_a_price_request_whatever_the_subject(): void
    {
        $this->assertTrue(self::thread('Re: Order EVE', ['rfq_token' => 'ABC1234'])->isPriceRequestThread());
        $this->assertTrue(self::thread('Re: Order EVE', ['created_by_user_id' => 9])->isPriceRequestThread());
        // Привязка к заявке по номеру в теме не делает заказ запросом цены.
        $this->assertFalse(self::thread('Re: Заказ для КЛР-Л', ['related_request_id' => 5])->isPriceRequestThread());
        $this->assertTrue(self::thread('Re: вопрос по позиции', ['related_request_id' => 5])->isPriceRequestThread());
    }
}
