<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Контакт личной адресной книги почты (у каждого пользователя своя).
 * Источник остальных подсказок — App\Services\Mail\AddressBookService.
 */
class AddressBookContact extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'name',
        'organization',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Адрес — в нижнем регистре: уникальность в книге без учёта регистра. */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim((string) $value));
    }
}
