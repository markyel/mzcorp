<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Контактное лицо клиента (раздел «Клиенты») — единица хранения по e-mail.
 * Связь с организациями — M:N через organization_contact.
 *
 * @property string $email
 * @property ?string $full_name
 * @property ?string $phone
 */
class ClientContact extends Model
{
    protected $fillable = [
        'email',
        'full_name',
        'phone',
        'notes',
        'pinned_organization_id',
        'pinned_at',
        'pinned_by_user_id',
    ];

    protected $casts = [
        'pinned_at' => 'datetime',
    ];

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_contact')
            ->withTimestamps()
            ->orderBy('name');
    }

    /**
     * Закреплённый заказчик: у этого адреса юрлицо одно, что бы ни было
     * написано в отдельных документах. Автоматика такие контакты не трогает.
     */
    public function pinnedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'pinned_organization_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_user_id');
    }

    public function isPinned(): bool
    {
        return $this->pinned_organization_id !== null;
    }

    /** Закреплён ли адрес (для сервисов, у которых на руках только строка). */
    public static function pinnedOrganizationIdFor(string $email): ?int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return static::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->value('pinned_organization_id');
    }
}
