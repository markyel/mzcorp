<?php

namespace App\Models;

use App\Enums\ClientNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись стоп-листа авто-уведомлений: для `email` не слать типы из
 * `suppressed_types`. См. ClientNotificationOptoutService + миграцию.
 *
 * @property string $email
 * @property array<int, string> $suppressed_types
 */
class ClientNotificationOptout extends Model
{
    protected $fillable = [
        'email',
        'suppressed_types',
        'comment',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_types' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Заглушён ли конкретный тип для этой записи.
     */
    public function suppresses(ClientNotificationType $type): bool
    {
        return in_array($type->value, (array) $this->suppressed_types, true);
    }
}
