<?php

namespace Tests\Unit\Supplier;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Services\Supplier\SupplierManualSearch;
use PHPUnit\Framework\TestCase;

class SupplierManualSearchTest extends TestCase
{
    private function row(int $id, ?int $org, string $email, array $topics = [], ?int $position = null, ?string $orgName = null): Supplier
    {
        $s = new Supplier;
        $s->forceFill(['id' => $id, 'supplier_organization_id' => $org, 'email' => $email, 'name' => null,
            'contact_topics' => $topics, 'contact_position' => $position]);
        if ($org !== null) {
            $o = new SupplierOrganization;
            $o->forceFill(['id' => $org, 'name' => $orgName ?? 'Org '.$org]);
            $s->setRelation('organization', $o);
        }

        return $s;
    }

    /** Три адреса YuanQi по названию — одна строка, по клику встаёт представитель «Расценка». */
    public function test_one_row_per_supplier_with_its_representative(): void
    {
        $yq = fn () => [
            $this->row(11, 35, 'ru.zip@eastelevator.cn', ['finance'], 1, 'YuanQi (ЮаньЧи) Китай'),
            $this->row(12, 35, 'sales.sanjin@westdt.com', ['quoting'], 2, 'YuanQi (ЮаньЧи) Китай'),
            $this->row(13, 35, 'yuanqi.lift@eastelevator.cn', ['quoting'], 3, 'YuanQi (ЮаньЧи) Китай'),
        ];

        $out = SupplierManualSearch::group(collect($yq()), collect($yq()), 'yua');

        $this->assertCount(1, $out);
        $this->assertSame('YuanQi (ЮаньЧи) Китай', $out[0]['name']);
        $this->assertSame(12, $out[0]['representative_id']);
        $this->assertSame(3, $out[0]['addresses']);
        // «yua» совпал с адресом yuanqi.lift@ — его можно выбрать отдельно; представитель в опциях не повторяется.
        $this->assertSame([['id' => 13, 'email' => 'yuanqi.lift@eastelevator.cn', 'in_request' => false]], $out[0]['emails']);
    }

    public function test_search_by_name_offers_no_specific_addresses(): void
    {
        $rows = [$this->row(1, 7, 'a@zagro.ch', ['quoting'], 1, 'Zagro AG'), $this->row(2, 7, 'b@zagro.ch', [], 2, 'Zagro AG')];

        $out = SupplierManualSearch::group(collect($rows), collect($rows), 'Zagro AG');

        $this->assertSame(1, $out[0]['representative_id']);
        $this->assertSame([], $out[0]['emails']);
    }

    /** Совпал один адрес организации — представитель всё равно из всех её адресов. */
    public function test_representative_comes_from_all_addresses_of_the_organization(): void
    {
        $matched = [$this->row(5, 9, 'logistics@acme.cn', ['logistics'], 3, 'ACME')];
        $all = [...$matched, $this->row(4, 9, 'quote@acme.cn', ['quoting'], 1, 'ACME')];

        $out = SupplierManualSearch::group(collect($matched), collect($all), 'logistics@');

        $this->assertSame(4, $out[0]['representative_id']);
        $this->assertSame([['id' => 5, 'email' => 'logistics@acme.cn', 'in_request' => false]], $out[0]['emails']);
    }

    /** Поставщик уже в запросе — не пропадает из поиска, а помечен (раньше его было не найти). */
    public function test_supplier_already_in_the_request_stays_found_and_marked(): void
    {
        $rows = [$this->row(1, 7, 'a@zagro.ch', ['quoting'], 1), $this->row(2, 7, 'b@zagro.ch', [], 2), $this->row(3, null, 'solo@x.ru')];

        $out = SupplierManualSearch::group(collect($rows), collect(array_slice($rows, 0, 2)), 'zagro', excludeIds: [2]);

        $this->assertSame(['o7', 's3'], array_column($out, 'key'));
        $this->assertTrue($out[0]['in_request']);
        $this->assertFalse($out[1]['in_request']);
    }

    /**
     * Заявка 19476: в списке gao@is-com.ru, ищут nes@is-com.ru той же организации — адрес
     * предложен отдельной строкой, даже если это представитель; уже стоящий в списке помечен.
     */
    public function test_address_of_a_supplier_already_in_the_request_is_offered_by_email(): void
    {
        $all = [
            $this->row(21, 40, 'gao@is-com.ru', [], 2, 'ООО НТП "Индустриальные Системы"'),
            $this->row(22, 40, 'nes@is-com.ru', ['quoting'], 1, 'ООО НТП "Индустриальные Системы"'),
        ];

        $out = SupplierManualSearch::group(collect([$all[1]]), collect($all), 'nes@is-com.ru', excludeIds: [21]);
        $this->assertTrue($out[0]['in_request']);
        $this->assertSame([['id' => 22, 'email' => 'nes@is-com.ru', 'in_request' => false]], $out[0]['emails']);

        $out = SupplierManualSearch::group(collect([$all[1]]), collect($all), 'nes@is-com.ru', excludeIds: [21, 22]);
        $this->assertSame([['id' => 22, 'email' => 'nes@is-com.ru', 'in_request' => true]], $out[0]['emails']);
    }
}
