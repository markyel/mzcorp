<?php

namespace Tests\Unit\Supplier;

use App\Models\SupplierOrganization;
use App\Services\Supplier\SupplierMergeService;
use PHPUnit\Framework\TestCase;

/** Склейка дублей контрагента 1С (25.11.2026, «Подий» 00-003020 ← 00-008624): код дубля — дополнительный. */
class SupplierMergeAliasesTest extends TestCase
{
    private function org(?string $code, array $aliases = []): SupplierOrganization
    {
        return (new SupplierOrganization)->forceFill(['onec_code' => $code, 'onec_aliases' => $aliases ?: null]);
    }

    public function test_duplicate_code_becomes_an_alias_of_the_main_one(): void
    {
        $this->assertSame(['00-008624'], SupplierMergeService::aliasesAfterMerge($this->org('00-003020'), $this->org('00-008624')));
    }

    public function test_aliases_accumulate_without_repeats_or_the_main_code(): void
    {
        $into = $this->org('00-003020', ['00-001111']);
        $from = $this->org('00-008624', ['00-003020', '00-001111', '00-002222']);

        $this->assertSame(['00-001111', '00-008624', '00-002222'], SupplierMergeService::aliasesAfterMerge($into, $from));
    }

    public function test_no_codes_no_aliases(): void
    {
        $this->assertSame([], SupplierMergeService::aliasesAfterMerge($this->org('00-003020'), $this->org(null)));
    }
}
