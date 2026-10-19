<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsRfqService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Копия ответа поставщику через QuoteParts — как в классической переписке. */
class QuotePartsAnswerCcTest extends TestCase
{
    public function test_addresses_with_names_and_separators(): void
    {
        $this->assertSame(
            ['boss@myzip.ru', 'olga@myzip.ru', 'x@y.cn'],
            QuotePartsRfqService::parseCc('Boss@MyZip.ru; "Ольга" <olga@myzip.ru>, x@y.cn'."\n".'boss@myzip.ru'),
        );
        $this->assertSame([], QuotePartsRfqService::parseCc('  '));
    }

    public function test_text_without_an_address_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        QuotePartsRfqService::parseCc('boss@myzip.ru, Ольга');
    }
}
