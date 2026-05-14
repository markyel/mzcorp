<?php

namespace App\Models;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI-решение DocumentDetector (Foundation §7.3 — audit + validation).
 *
 * Каждое срабатывание outbound-детектора или inbound-classifier создаёт
 * запись со status=suggested. UI prompt оператора переводит в один из
 * терминалов (auto_applied / manually_confirmed / manually_overridden /
 * dismissed). Counters агрегируются для AI quality score дашборда.
 */
class AiDecision extends Model
{
    protected $fillable = [
        'detector_type',
        'status',
        'request_id',
        'email_message_id',
        'confidence',
        'payload',
        'applied_at',
        'applied_by_user_id',
        'override_to_status',
    ];

    protected function casts(): array
    {
        return [
            'detector_type' => DetectorType::class,
            'status' => AiDecisionStatus::class,
            'confidence' => 'float',
            'payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
