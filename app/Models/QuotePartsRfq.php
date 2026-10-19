<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запрос поставщикам, отправленный через QuoteParts (см. QuotePartsRfqService).
 * snapshot — последний ответ GET /api/v1/rfqs/{id}: поставщики, строки, офферы.
 */
class QuotePartsRfq extends Model
{
    protected $table = 'quoteparts_rfqs';

    protected $fillable = [
        'remote_id',
        'tag',
        'request_id',
        'created_by_user_id',
        'reply_by',
        'status',
        'snapshot',
        'synced_at',
    ];

    protected $casts = [
        'reply_by' => 'date',
        'snapshot' => 'array',
        'synced_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Запрос открыл поставщик вопросом по позиции из своей номенклатуры (её у него не запрашивали). */
    public const ORIGIN_SUPPLIER_QUESTION = 'supplier_question';

    public function isSupplierQuestion(): bool
    {
        return ($this->snapshot['origin'] ?? null) === self::ORIGIN_SUPPLIER_QUESTION;
    }
}
