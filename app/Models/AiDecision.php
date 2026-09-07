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

    /**
     * Ключ payload: до какого момента (ISO-8601) suggestion считается
     * «ждём разбор документа» и НЕ показывается менеджеру. Ставится в
     * recordSuggestion для типов requiresDocumentEvidence(), когда у письма
     * есть парсимое вложение; снимается ParseOutboundQuoteJob по завершении
     * разбора (любой исход). Дедлайн — страховка от потерянной job'ы.
     */
    public const PAYLOAD_AWAITING_PARSE_UNTIL = 'awaiting_document_parse_until';

    /**
     * Suggestion ещё ждёт разбора документа (плашку не показываем — иначе
     * менеджер подтверждает вручную за 2–10 с, пока парсер работает, и
     * «автоматика не работает»). Кейс M-2026-14815.
     */
    public function isAwaitingDocumentParse(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== AiDecisionStatus::Suggested) {
            return false;
        }
        $until = $this->payload[self::PAYLOAD_AWAITING_PARSE_UNTIL] ?? null;
        if (! is_string($until) || $until === '') {
            return false;
        }
        try {
            $deadline = new \DateTimeImmutable($until);
        } catch (\Throwable) {
            return false;
        }

        return $deadline > ($now ?? now());
    }

    /**
     * Scope: suggestion'ы, которые можно показать менеджеру — без тех, что
     * ждут разбора документа (см. isAwaitingDocumentParse).
     */
    public function scopeActionable(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $nowIso = now()->toIso8601String();

        return $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($nowIso) {
            $q->whereNull('payload->' . self::PAYLOAD_AWAITING_PARSE_UNTIL)
                ->orWhere('payload->' . self::PAYLOAD_AWAITING_PARSE_UNTIL, '<=', $nowIso);
        });
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
