<?php

namespace Tests\Unit\Retro;

use App\Services\Retro\RetroIndex;
use App\Services\Retro\RetroSupplierCollector;
use PHPUnit\Framework\TestCase;

class RetroSupplierCollectorTest extends TestCase
{
    private const NOW = 1793000000;

    private int $uid = 0;

    private function collector(): RetroSupplierCollector
    {
        return new RetroSupplierCollector([
            'internal_domains' => ['myzip.ru'],
            'free_mail_domains' => ['mail.ru'],
            'service_sender_pattern' => '/^(mailer-daemon|no-?reply)[^@]*@/i',
        ]);
    }

    private function row(array $raw, bool $outbound): array
    {
        return RetroIndex::normalize($raw + [
            'uid' => ++$this->uid, 'folder' => $outbound ? 'Sent' : 'INBOX', 'date' => date('r', self::NOW - 86400 * $this->uid),
            'from' => $outbound ? 'manager@myzip.ru' : 'sales@supplier.cn', 'to' => $outbound ? 'sales@supplier.cn' : 'manager@myzip.ru',
            'mid' => '<'.$this->uid.'@x>',
        ], 'manager@myzip.ru', 2026, 25000);
    }

    public function test_addressees_of_marked_requests_and_their_repliers_are_suppliers(): void
    {
        $suppliers = $this->collector()->collect([
            $this->row(['to' => 'Kid <sales@supplier.cn>, Сергей <sergey@myzip.ru>', 'subject' => 'Price request — [M-2026-1000] / [360001] [RFQ-ABCD12]'], true),
            $this->row(['to' => 'sales@supplier.cn', 'subject' => 'Price request — [M-2026-1001]'], true),
            $this->row(['from' => 'Kid Xue <kid@supplier.cn>', 'subject' => 'RE: Price request — [M-2026-1000] / [360001] [RFQ-ABCD12]'], false),
            $this->row(['to' => 'Петров <petrov@zavod.ru>', 'subject' => 'Запрос расценки — [M-2026-1002]'], true),
            $this->row(['to' => 'ryan@mail.ru', 'subject' => 'Re: [QP-ZX81Q2] Кнопка'], true),
            // Не маркировано — клиенту, не поставщику.
            $this->row(['to' => 'ivan@client.ru', 'subject' => 'Re: Запрос КП'], true),
            $this->row(['from' => 'mailer-daemon@supplier.cn', 'subject' => 'RE: Price request — [M-2026-1001]'], false),
        ]);

        $this->assertSame(['kid@supplier.cn', 'petrov@zavod.ru', 'ryan@mail.ru', 'sales@supplier.cn'], array_keys($suppliers));
        $this->assertSame(2, $suppliers['sales@supplier.cn']['requests']);
        $this->assertSame('en', $suppliers['sales@supplier.cn']['language']);
        $this->assertSame('Kid', $suppliers['sales@supplier.cn']['contact']);
        $this->assertSame(1, $suppliers['kid@supplier.cn']['replies']);
        $this->assertSame('Kid Xue', $suppliers['kid@supplier.cn']['contact']);
        $this->assertSame('ru', $suppliers['petrov@zavod.ru']['language']);
        $this->assertSame('Петров', $suppliers['petrov@zavod.ru']['contact']);
        $this->assertTrue($suppliers['ryan@mail.ru']['free_mail']);
        $this->assertSame('ru', $suppliers['ryan@mail.ru']['language'], 'Кириллица в теме — русский');
    }
}
