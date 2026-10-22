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

    /** Почему поставщик застрял — подписи для блока «Запросы через QuoteParts» в «Снабжении». */
    public const STUCK_LABELS = [
        'error' => 'письмо не ушло',
        'question' => 'задал вопрос — ответьте',
        'no_offer' => 'ответил без оффера',
        'silent' => 'тишина, напоминания кончились',
    ];

    /**
     * Застрявшие поставщики открытого запроса — как «Застрявшие» у прямых запросов: позиции ещё ждут
     * ответа (не отложены поставщиком), а движения нет:
     *  - письмо не ушло;
     *  - поставщик спросил, а последнее письмо не наше;
     *  - поставщик ответил без цены, а последнее письмо не наше;
     *  - тишина, напоминания исчерпаны (или срок ответа прошёл).
     * Последнее письмо наше (ответили, уточнили) — ход за поставщиком, не застрял.
     *
     * @return array<string, string> external_id поставщика => причина (ключ STUCK_LABELS)
     */
    public function stuckSuppliers(int $maxReminders = 2): array
    {
        if ($this->status !== 'open') {
            return [];
        }
        $today = now()->toDateString();
        $out = [];
        foreach ($this->snapshot['suppliers'] ?? [] as $s) {
            $waiting = collect($s['lines'] ?? [])->filter(fn ($l) => in_array($l['status'] ?? null, ['pending', 'question'], true)
                && ! (! empty($l['postponed_until']) && $l['postponed_until'] > $today));
            if ($waiting->isEmpty()) {
                continue;
            }
            $key = (string) ($s['external_id'] ?? '');
            $last = collect($s['thread'] ?? [])->sortBy('at')->last();
            $lastOurs = ($last['direction'] ?? null) === 'out';
            $reason = match (true) {
                empty($s['sent']) && ! empty($s['send_error']) => 'error',
                $lastOurs => null,
                $waiting->contains(fn ($l) => ($l['status'] ?? null) === 'question') => 'question',
                ! empty($s['last_reply_at']) => 'no_offer',
                (int) ($s['reminders_sent'] ?? 0) >= $maxReminders || ($this->reply_by !== null && $this->reply_by->lt(now()->startOfDay())) => 'silent',
                default => null,
            };
            if ($reason !== null) {
                $out[$key] = $reason;
            }
        }

        return $out;
    }
}
