<?php

namespace Tests\Unit\Services\Supplier;

use App\Models\Supplier;
use App\Services\Supplier\SupplierOrganizationService;
use PHPUnit\Framework\TestCase;

/**
 * Название новой организации поставщика подбирается из названий её адресов:
 * снабженец объединяет в один клик и не должен каждый раз печатать имя.
 */
class SupplierOrganizationNameTest extends TestCase
{
    private function supplier(?string $email, ?string $name = null, ?string $domain = null): Supplier
    {
        $s = new Supplier;
        $s->email = $email;
        $s->name = $name;
        $s->domain = $domain;

        return $s;
    }

    public function test_the_most_frequent_name_wins(): void
    {
        $name = (new SupplierOrganizationService)->suggestName(collect([
            $this->supplier('info@keb.ru', 'КЕБ-РУС'),
            $this->supplier('r.obukhov@keb.ru', 'КЕБ-РУС'),
            $this->supplier('i.sokolovskaya@keb.ru', 'KEB (официальный дилер Россия)'),
        ]));

        $this->assertSame('КЕБ-РУС', $name);
    }

    public function test_without_names_the_corporate_domain_is_used(): void
    {
        $name = (new SupplierOrganizationService)->suggestName(collect([
            $this->supplier('info@segz.ru'),
            $this->supplier('gp87@segz.ru', '"gp87@segz.ru"'),
        ]));

        $this->assertSame('segz.ru', $name);
    }

    public function test_domain_comes_from_the_domain_field_or_the_email(): void
    {
        $this->assertSame('kmz.mos.ru', SupplierOrganizationService::domainOf($this->supplier('Sorokinra2@KMZ.mos.ru')));
        $this->assertSame('sassi.it', SupplierOrganizationService::domainOf($this->supplier(null, null, 'Sassi.it')));
        $this->assertNull(SupplierOrganizationService::domainOf($this->supplier(null)));
    }
}
