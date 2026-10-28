<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пресс-релиз: инфоповод → текст → рассылка по редакциям → публикации.
 */
class PressRelease extends Model
{
    public const STATUSES = [
        'idea' => 'инфоповод',
        'draft' => 'черновик',
        'ready' => 'готов к рассылке',
        'sent' => 'разослан',
        'archived' => 'в архиве',
    ];

    protected $fillable = [
        'title', 'occasion', 'body', 'subject', 'cover_note', 'status', 'planned_for',
        'mailbox_id', 'sent_at', 'model', 'created_by_user_id',
    ];

    protected $casts = [
        'planned_for' => 'date',
        'sent_at' => 'datetime',
    ];

    public function sends(): HasMany
    {
        return $this->hasMany(PressReleaseSend::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(PressMention::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    /** Текст готов настолько, что его можно разослать. */
    public function isSendable(): bool
    {
        return trim((string) $this->body) !== '' && trim((string) $this->subject) !== '';
    }
}
