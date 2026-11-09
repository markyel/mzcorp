<?php

namespace Tests\Unit\Supplier;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use PHPUnit\Framework\TestCase;

/** Ключ поставщика в QuoteParts — qp_key организации; без него — прежняя схема org:{id} / sup:{id}. */
class SupplierQuotepartsKeyTest extends TestCase
{
    private function supplier(?array $org): Supplier
    {
        $s = new Supplier;
        $s->forceFill(['id' => 7, 'supplier_organization_id' => $org === null ? null : 9]);
        if ($org !== null) {
            $o = new SupplierOrganization;
            $o->forceFill($org);
            $s->setRelation('organization', $o);
        }

        return $s;
    }

    public function test_loaded_organization_with_key_gives_the_key(): void
    {
        $this->assertSame('00-000523', $this->supplier(['id' => 9, 'name' => 'OTI', 'qp_key' => '00-000523'])->quotepartsKey());
    }

    public function test_organization_without_bound_key_falls_back_to_org_id(): void
    {
        $this->assertSame('org:9', $this->supplier(['id' => 9, 'name' => 'OTI', 'qp_key' => null])->quotepartsKey());
    }

    /**
     * Связь загружена как organization:id,name — в ней нет qp_key. Раньше это молча давало
     * «org:9»; теперь ключ дочитывается из базы (у несохранённой модели читать неоткуда — org:9).
     */
    public function test_organization_loaded_without_key_column_is_not_trusted(): void
    {
        $s = $this->supplier(['id' => 9, 'name' => 'OTI']);

        $this->assertFalse(array_key_exists('qp_key', $s->organization->getAttributes()));
        $this->assertSame('org:9', $s->quotepartsKey()); // exists=false: в базу не ходим
    }

    public function test_address_without_organization_is_its_own_supplier(): void
    {
        $this->assertSame('sup:7', $this->supplier(null)->quotepartsKey());
    }
}
