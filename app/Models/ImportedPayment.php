<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка импорта оплат из 1С. outcome:
 *   marked_paid    — счёт найден и отмечен оплаченным;
 *   marked_partial — счёт найден, частичная оплата (Оп% < 100);
 *   already_paid   — счёт уже был оплачен, пропущено;
 *   unknown        — счёта нет в системе → «Внешние оплаты» (ждёт привязки);
 *   skipped_old    — счёт выписан до запуска системы, не фиксируем;
 *   ignored        — оператор пометил внешнюю оплату как неактуальную;
 *   linked         — внешняя оплата привязана к заявке (создан счёт+оплата);
 *   error          — сбой обработки строки (см. note).
 */
class ImportedPayment extends Model
{
    public const OUTCOME_MARKED_PAID = 'marked_paid';
    public const OUTCOME_MARKED_PARTIAL = 'marked_partial';
    public const OUTCOME_ALREADY_PAID = 'already_paid';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_SKIPPED_OLD = 'skipped_old';
    public const OUTCOME_IGNORED = 'ignored';
    public const OUTCOME_LINKED = 'linked';
    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'payment_import_id',
        'invoice_number',
        'invoice_number_int',
        'invoice_id',
        'request_id',
        'outcome',
        'client_name',
        'manager_name',
        'payment_purpose',
        'invoice_date',
        'paid_date',
        'paid_percent',
        'paid_sum',
        'debt_sum',
        'revenue_sum',
        'cost_sum',
        'profit_sum',
        'note',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'paid_date' => 'date',
            'paid_percent' => 'integer',
            'paid_sum' => 'decimal:2',
            'debt_sum' => 'decimal:2',
            'revenue_sum' => 'decimal:2',
            'cost_sum' => 'decimal:2',
            'profit_sum' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PaymentImport::class, 'payment_import_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
