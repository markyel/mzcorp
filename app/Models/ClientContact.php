<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
    ];

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_contact')
            ->withTimestamps()
            ->orderBy('name');
    }
}
