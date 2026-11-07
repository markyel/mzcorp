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
    // inn — у организации, переведённой из клиентов (OrganizationToSupplierService).
    protected $fillable = ['name', 'inn', 'notes', 'created_by_user_id',
        // Ключ в QuoteParts: код 1С или временный «mz:{id}» (см. миграцию add_qp_key_and_onec).
        'qp_key', 'onec_code', 'onec_name', 'onec_candidates', 'qp_status', 'qp_payload', 'qp_synced_at',
        // Архив (SupplierArchiveService): не виден в реестре, не предлагается в запросах цен.
        'archived_at', 'archived_reason', 'archived_by_user_id'];

    protected function casts(): array
    {
        return [
            'onec_candidates' => 'array',
            'qp_payload' => 'array',
            'qp_synced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<SupplierOrganization>  $query */
    public function scopeNotArchived($query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<SupplierOrganization>  $query */
    public function scopeArchived($query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** Связь поставщика есть в QuoteParts: правки (и архив) идут туда через API. */
    public function inQuoteParts(): bool
    {
        return (string) $this->qp_key !== '' && $this->qp_status !== null;
    }

    public function archivedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    /** Временный ключ до кода 1С: не меняется при слияниях и переименованиях. */
    public static function temporaryKey(int $id): string
    {
        return 'mz:'.$id;
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
