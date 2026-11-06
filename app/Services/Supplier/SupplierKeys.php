<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use Illuminate\Support\Collection;

/**
 * Ключ поставщика в QuoteParts → наш поставщик. Ключ — supplier_organizations.qp_key (код 1С или
 * временный «mz:{id}»); прежние «org:{id}» / «sup:{id}» понимаются, пока где-то лежат.
 */
class SupplierKeys
{
    public function organization(string $key): ?SupplierOrganization
    {
        $org = SupplierOrganization::query()->where('qp_key', $key)->first();
        if ($org !== null) {
            return $org;
        }
        if (str_starts_with($key, 'org:')) {
            return SupplierOrganization::query()->find((int) substr($key, 4));
        }
        if (str_starts_with($key, 'sup:')) {
            return Supplier::query()->find((int) substr($key, 4))?->organization;
        }

        return null;
    }

    /**
     * Адреса поставщика по ключу (контакты организации, по порядку).
     *
     * @return Collection<int, Supplier>
     */
    public function suppliers(string $key): Collection
    {
        if (str_starts_with($key, 'sup:') && SupplierOrganization::query()->where('qp_key', $key)->doesntExist()) {
            $one = Supplier::query()->find((int) substr($key, 4));
            if ($one !== null && $one->supplier_organization_id === null) {
                return collect([$one]);
            }
        }
        $org = $this->organization($key);

        return $org === null ? collect() : Supplier::query()->where('supplier_organization_id', $org->id)
            ->orderByRaw('contact_position nulls last')->orderBy('id')->get();
    }

    /**
     * Названия поставщиков по ключам: организация, иначе название или адрес единственного адреса.
     *
     * @param  iterable<string>  $keys
     * @return Collection<string, string>
     */
    public function names(iterable $keys): Collection
    {
        $out = collect();
        foreach (collect($keys)->unique()->values() as $key) {
            $org = $this->organization((string) $key);
            if ($org !== null) {
                $out[$key] = $org->name;

                continue;
            }
            $one = $this->suppliers((string) $key)->first();
            if ($one !== null) {
                $out[$key] = $one->name ?: (string) $one->email;
            }
        }

        return $out;
    }
}
