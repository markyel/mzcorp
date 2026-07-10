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
        'name_en',
        'email',
        'phone',
        'phone_extension',
        'mobile_phone',
        'password',
        'email_signature',
        'unavailable_from',
        'unavailable_until',
        'unavailable_reason',
        'unavailable_auto_delegate',
        // Плановая нагрузка в %; 100 — норма, 50 — в 2 раза меньше, 200 — в 2 раза больше.
        // См. App\Services\Request\AssignmentService::pickWeightedLeastLoadedManager.
        'load_weight',
        // Персональный порядок писем в табе «Переписка»: 'asc' (старые сверху)
        // или 'desc' (новые сверху). Применяется ко всем заявкам пользователя.
        'thread_sort_order',
        // Персональный дефолтный период дашборда (preset 1/7/30/90 дней).
        // См. App\Livewire\Dashboard\Index::setPeriod / mount.
        'dashboard_period_days',
        // Аватарки (относительные пути на диске local). 3 варианта:
        // нейтральный / победитель / проигравший. См. avatarUrl* ниже.
        'avatar_neutral_path',
        'avatar_won_path',
        'avatar_lost_path',
        // Персональные тексты письма поставщику (таб «Поставщики»): вступительный
        // и завершающий, отдельно рус./англ. NULL = используется системный дефолт.
        // См. App\Livewire\Requests\SupplierDispatchPanel::savePersonalText / mount.
        'supplier_intro_ru',
        'supplier_intro_en',
        'supplier_closing_ru',
        'supplier_closing_en',
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
            'unavailable_from' => 'datetime',
            'unavailable_until' => 'datetime',
            'unavailable_auto_delegate' => 'boolean',
            'load_weight' => 'integer',
            'dashboard_period_days' => 'integer',
            'updates_seen_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Диск хранения аватарок (private). */
    public const AVATAR_DISK = 'local';

    /** Допустимые варианты аватарки. */
    public const AVATAR_VARIANTS = ['neutral', 'won', 'lost'];

    /**
     * Относительный путь файла аватарки данного варианта (или null).
     */
    public function avatarPath(string $variant): ?string
    {
        $col = match ($variant) {
            'neutral' => 'avatar_neutral_path',
            'won' => 'avatar_won_path',
            'lost' => 'avatar_lost_path',
            default => null,
        };

        $path = $col ? ($this->{$col} ?? null) : null;

        return $path !== null && $path !== '' ? $path : null;
    }

    public function hasAvatar(string $variant = 'neutral'): bool
    {
        return $this->avatarPath($variant) !== null;
    }

    /**
     * URL для отдачи аватарки через UserAvatarController. Версия в query
     * (`?v=`) завязана на путь файла → при замене картинки меняется и URL,
     * браузерный кэш сбрасывается. null — если аватарки этого варианта нет.
     */
    public function avatarUrl(string $variant = 'neutral'): ?string
    {
        $path = $this->avatarPath($variant);
        if ($path === null) {
            return null;
        }

        return route('user.avatar', ['user' => $this->id, 'variant' => $variant])
            . '?v=' . substr(md5($path), 0, 8);
    }

    /**
     * Аватарка под статус заявки: closed_won → «победитель», closed_lost →
     * «проигравший» (если такой вариант загружен), иначе — нейтральный.
     * Если won/lost не загружены — fallback на нейтральный.
     */
    public function avatarUrlForStatus(?\App\Enums\RequestStatus $status): ?string
    {
        if ($status === \App\Enums\RequestStatus::ClosedWon && $this->hasAvatar('won')) {
            return $this->avatarUrl('won');
        }
        if ($status === \App\Enums\RequestStatus::ClosedLost && $this->hasAvatar('lost')) {
            return $this->avatarUrl('lost');
        }

        return $this->avatarUrl('neutral');
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
     * Foundation Фаза 2: менеджер «доступен» (получает новые заявки).
     *
     * Доступен iff:
     *   - archived_at IS NULL (не в архиве);
     *   - unavailable_until IS NULL (никогда не помечался) ИЛИ
     *     unavailable_until <= now() (вернулся) ИЛИ
     *     unavailable_from > now() (запланировано, но ещё не наступило).
     *
     * Используется AssignmentService для исключения из distribution.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('archived_at')
            ->where(function ($q) {
                $q->whereNull('unavailable_until')
                    ->orWhere('unavailable_until', '<=', now())
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('unavailable_from')
                            ->where('unavailable_from', '>', now());
                    });
            });
    }

    /**
     * Сейчас в окне отсутствия (период идёт).
     * `from IS NULL OR from <= now` AND `until > now`.
     */
    public function isUnavailable(): bool
    {
        if ($this->unavailable_until === null || $this->unavailable_until->isPast()) {
            return false;
        }
        if ($this->unavailable_from !== null && $this->unavailable_from->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Отсутствие запланировано, но ещё не началось.
     * `from > now AND until > from`.
     */
    public function isUnavailabilityPlanned(): bool
    {
        return $this->unavailable_from !== null
            && $this->unavailable_from->isFuture()
            && $this->unavailable_until !== null
            && $this->unavailable_until->greaterThan($this->unavailable_from);
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
