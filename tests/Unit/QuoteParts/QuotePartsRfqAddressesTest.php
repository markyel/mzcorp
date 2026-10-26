<?php

namespace Tests\Unit\QuoteParts;

use App\Models\Supplier;
use App\Services\QuoteParts\QuotePartsRfqService;
use Tests\TestCase;

/**
 * Один запрос — один ящик (решение 26.10.2026): QuoteParts получает отмеченные менеджером
 * адреса по ключу поставщика и шлёт запрос и напоминания только на них.
 */
class QuotePartsRfqAddressesTest extends TestCase
{
    private static function supplier(int $id, ?string $email, ?int $org = null): Supplier
    {
        $s = new Supplier(['email' => $email, 'supplier_organization_id' => $org]);
        $s->id = $id;

        return $s;
    }

    public function test_the_chosen_mailbox_goes_under_its_organisation_key(): void
    {
        $out = QuotePartsRfqService::addresses(collect([
            self::supplier(1, 'info@unisystem.si', 19),
            self::supplier(7, ' Steven@OSS-elevator-parts.com '),
        ]));

        $this->assertSame([
            'org:19' => ['info@unisystem.si'],
            'sup:7' => ['steven@oss-elevator-parts.com'],
        ], $out);
    }

    public function test_two_chosen_mailboxes_of_one_organisation_are_both_its_recipients(): void
    {
        $out = QuotePartsRfqService::addresses(collect([
            self::supplier(1, 'info@unisystem.si', 19),
            self::supplier(2, 'alex@unisystem.si', 19),
            self::supplier(3, 'INFO@unisystem.si', 19),
            self::supplier(4, '', 19),
        ]));

        $this->assertSame(['org:19' => ['info@unisystem.si', 'alex@unisystem.si']], $out);
    }
}
