<?php

namespace Tests\Unit\Supplier;

use App\Models\Supplier;
use App\Services\Supplier\SupplierMatchService;
use PHPUnit\Framework\TestCase;

class SupplierRepresentativesTest extends TestCase
{
    private function row(int $id, ?int $org, array $topics, ?int $position): Supplier
    {
        $s = new Supplier;
        $s->forceFill(['id' => $id, 'supplier_organization_id' => $org, 'contact_topics' => $topics, 'contact_position' => $position]);

        return $s;
    }

    public function test_one_address_per_supplier_the_first_quoting_contact(): void
    {
        $picked = SupplierMatchService::representatives(collect([
            $this->row(1, 7, ['finance'], 1),
            $this->row(2, 7, ['quoting'], 3),
            $this->row(3, 7, ['quoting', 'general'], 2),
            $this->row(4, null, [], null),
            $this->row(5, 9, [], null),
            $this->row(6, 9, [], 4),
        ]));

        $this->assertSame([3, 4, 6], $picked->pluck('id')->sort()->values()->all());
    }

    public function test_a_picked_address_without_quoting_is_flagged(): void
    {
        $info = $this->row(72, 6, ['general', 'quoting'], 1);
        $info->email = 'info@unisystem.si';
        $alex = $this->row(12, 6, ['logistics'], 3);
        $alex->email = 'alex@unisystem.si';
        $noRoles = $this->row(20, 9, [], 1);
        $loose = $this->row(30, null, ['finance'], null);
        $org = collect([$info, $alex, $noRoles, $this->row(21, 9, [], 2)]);

        $this->assertSame(
            [['email' => 'alex@unisystem.si', 'topics' => ['Логистика'], 'default' => 'info@unisystem.si']],
            SupplierMatchService::nonQuotingPicks(collect([$alex, $info, $noRoles, $loose]), $org),
        );
        $this->assertSame([], SupplierMatchService::nonQuotingPicks(collect([$info]), $org));
    }
}
