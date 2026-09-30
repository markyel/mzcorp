<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Организация поставщика — несколько адресов реестра одной компании.
 * Письма по-прежнему уходят на адрес (Supplier), организация объединяет их
 * в списках и даёт общее название.
 *
 * @property string $name
 * @property ?string $notes
 */
class SupplierOrganization extends Model
{
    protected $fillable = ['name', 'notes', 'created_by_user_id'];

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
