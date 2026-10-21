<?php

namespace Tests\Unit\Procurement;

use App\Models\OnecCounterparty;
use PHPUnit\Framework\TestCase;

/** Название контрагента 1С для сравнения: регистр, кавычки и лишние пробелы не важны. */
class OnecCounterpartyNameTest extends TestCase
{
    public function test_normalize(): void
    {
        $this->assertSame('oti (es escalator parts company)', OnecCounterparty::normalize('OTI  (ES Escalator Parts Company) '));
        $this->assertSame('ооо синтэк', OnecCounterparty::normalize('ООО "Синтэк"'));
        $this->assertSame('ооо синтэк', OnecCounterparty::normalize('ООО «СИНТЭК»'));
        $this->assertSame('', OnecCounterparty::normalize(null));
    }
}
