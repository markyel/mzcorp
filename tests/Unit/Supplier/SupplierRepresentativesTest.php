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
}
