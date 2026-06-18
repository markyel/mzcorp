<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись реестра поставщиков (модуль поставщиков): email и/или домен,
 * который мы считаем поставщиком. См. миграцию create_suppliers_table и
 * App\Services\Supplier\SupplierRegistry.
 *
 * @property ?string $email
 * @property ?string $domain
 * @property ?string $name
 * @property ?string $notes
 * @property ?int $created_by_user_id
 */
class Supplier extends Model
{
    protected $fillable = [
        'email',
        'domain',
        'name',
        'phone',
        'notes',
        // Фаза 3.1 — профиль для подбора под позицию.
        'assortment_description',
        'assortment_matrix',
        'matrix_built_at',
        'matrix_built_with_model',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'assortment_matrix' => 'array',
            'matrix_built_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
