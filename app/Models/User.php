<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MailboxType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Личные ящики менеджера (mailboxes.type = personal).
     * У РОПа/секретаря/директора может быть пусто.
     */
    public function ownedMailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'owner_user_id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_signature',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'archived_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Только активные пользователи (`archived_at IS NULL`).
     * Используется в AssignmentService и UI-фильтрах.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Первый active personal mailbox менеджера, годный для отправки исходящих
     * (Phase 1.9 — OutgoingMailboxResolver). Может быть null — тогда fallback
     * на shared mailbox в resolver'е.
     */
    public function primaryOutboundMailbox(): ?Mailbox
    {
        return $this->ownedMailboxes()
            ->where('type', MailboxType::Personal)
            ->where('is_active', true)
            ->get()
            ->first(fn (Mailbox $m) => $m->canSendOutbound());
    }
}
