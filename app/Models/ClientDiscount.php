<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Скидка контрагента из выгрузки корпоративной базы.
 * См. миграцию 2026_09_21_210000_create_client_discounts_table.
 */
class ClientDiscount extends Model
{
    protected $fillable = [
        'inn', 'name', 'group_name', 'discount_percent',
        'organization_id', 'source_file', 'imported_by_user_id',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    /**
     * ИНН из выгрузки: Excel съедает ведущий ноль, и «0276088789» приезжает
     * как «276088789». Дополняем слева — иначе 18 контрагентов из 853 не
     * сопоставятся ни с одной организацией.
     */
    public static function normalizeInn(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (mb_strlen($digits) === 9) {
            $digits = '0'.$digits;
        }
        if (mb_strlen($digits) === 11) {
            $digits = '0'.$digits;
        }

        return in_array(mb_strlen($digits), [10, 12], true) ? $digits : null;
    }
}
