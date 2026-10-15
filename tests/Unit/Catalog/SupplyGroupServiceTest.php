<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\SupplyGroupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupplyGroupServiceTest extends TestCase
{
    /** @return array<string, array{0:string, 1:array<string,int>, 2:?string}> */
    public static function suppliers(): array
    {
        return [
            'chinese by currency' => ['OSS', ['USD' => 5743], 'cn'],
            'china in the name' => ['YuanQi (ЮаньЧи) Китай', ['USD' => 1477], 'cn'],
            'european by currency' => ['Elevatorcommerce', ['EUR' => 2582], 'eu'],
            'country in the name' => ['DONATI SRL Italy (Цены смотрим на сайте www.donati.it НЕ ЗАПРАШИВАТЬ НАПРЯМУЮ)', ['EUR' => 522], 'eu'],
            'latin name, russian note' => ['KONE Global Spares GSS (ПРЯМЫЕ ЗАПРОСЫ НЕ СЛАТЬ - через Sacha Rodich)', ['EUR' => 588], 'eu'],
            'turkey is europe' => ['Heralift / VECTA Lift (Консолидация в Турции) (ЕСТЬ ПРАЙС НА ARKEL)', ['EUR' => 713], 'eu'],
            'russian firm buying in euro' => ['КЛЕМАН ЛИФТС РУС ООО от КЛР-Л (г.Москва) (Электронный документооборот)', ['EUR' => 263], 'ru'],
            'russian firm quoting in dollars' => ['С-Лифт (Дальтехимпорт)ООО (запрашивать всегда цену в долларах!!!)', ['USD' => 296], 'ru'],
            'russian by currency' => ['Шиндлер ЗАО / ТопРендж (Поставщик)', ['RUB' => 657], 'ru'],
            'russia in the name' => ['GENEMEK Россия', ['USD' => 29, 'RUB' => 16], 'ru'],
            'nothing to go by'=> ['Unknown Ltd', ['UAH' => 2], null],
        ];
    }

    #[DataProvider('suppliers')]
    public function test_region_of_a_1c_supplier(string $name, array $currencies, ?string $region): void
    {
        $this->assertSame($region, SupplyGroupService::classify($name, $currencies));
    }

    public function test_groups_of_an_item_by_its_suppliers_regions(): void
    {
        $rules = ['cn' => null, 'ru' => null, 'eu' => 'cn'];
        $this->assertSame(['cn'], SupplyGroupService::groupsOf(['cn'], $rules));
        $this->assertSame(['cn', 'ru'], SupplyGroupService::groupsOf(['ru', 'cn'], $rules));
        // Europe — only what is imported from Europe and not from China.
        $this->assertSame(['eu'], SupplyGroupService::groupsOf(['eu'], $rules));
        $this->assertSame(['ru', 'eu'], SupplyGroupService::groupsOf(['eu', 'ru'], $rules));
        $this->assertSame(['cn'], SupplyGroupService::groupsOf(['eu', 'cn'], $rules));
        // A new group without a rule: whenever bought from its region.
        $this->assertSame(['cn', 'tr'], SupplyGroupService::groupsOf(['tr', 'cn'], $rules + ['tr' => null]));
    }
}
