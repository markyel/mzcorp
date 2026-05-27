<?php

namespace App\Models;

use App\Enums\ClientNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Шаблон автоматического уведомления клиенту.
 *
 * 1 row на каждый ClientNotificationType (uniq on type). Admin может
 * редактировать subject/body и переключать is_enabled через UI
 * Admin/Notifications. См. enum для placeholder'ов.
 */
class ClientNotificationTemplate extends Model
{
    protected $fillable = [
        'type',
        'is_enabled',
        'subject_template',
        'body_template',
        'threshold_hours',
        'warning_days',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientNotificationType::class,
            'is_enabled' => 'bool',
            'threshold_hours' => 'int',
            'warning_days' => 'int',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Найти template по типу (или создать с пустыми полями, если seeder
     * почему-то ещё не отработал). Удобно в hook'ах не падать NotFound'ом.
     */
    public static function forType(ClientNotificationType $type): self
    {
        return self::firstOrCreate(
            ['type' => $type->value],
            [
                'is_enabled' => false,
                'subject_template' => '',
                'body_template' => '',
            ]
        );
    }
}
