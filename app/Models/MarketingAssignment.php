<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Поручение маркетинга соседнему отделу: инструкция + файлы, статус у нас.
 */
class MarketingAssignment extends Model
{
    public const STATUSES = [
        'draft' => 'готовится',
        'handed' => 'передано',
        'in_progress' => 'в работе',
        'done' => 'сделано',
        'cancelled' => 'снято',
    ];

    public const PRIORITIES = [1 => 'высокий', 2 => 'обычный', 3 => 'низкий'];

    protected $fillable = [
        'title', 'executor', 'executor_contact', 'brief', 'instructions', 'status', 'priority',
        'due_on', 'handed_at', 'done_at', 'result_notes', 'model', 'created_by_user_id',
    ];

    protected $casts = [
        'priority' => 'integer',
        'due_on' => 'date',
        'handed_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(MarketingAssignmentFile::class)->orderBy('id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'handed', 'in_progress'], true);
    }

    /** Срок прошёл, а поручение не закрыто. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(today());
    }
}
