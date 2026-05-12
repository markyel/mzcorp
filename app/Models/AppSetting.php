<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись настройки приложения, редактируемой через UI «Настройки».
 * См. миграцию `2026_05_12_240000_create_app_settings_table.php` и
 * `App\Services\Settings\SettingsService`.
 *
 * Атрибут `typed_value` возвращает PHP-значение нужного типа (int/float/
 * bool/array/string) из строкового хранения в `value`.
 */
class AppSetting extends Model
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOL = 'bool';
    public const TYPE_JSON = 'json';

    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_INT,
        self::TYPE_FLOAT,
        self::TYPE_BOOL,
        self::TYPE_JSON,
    ];

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'updated_by_user_id',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Привести `value` к PHP-типу согласно `type`. Падает на сыром string
     * если type некорректный.
     */
    public function getTypedValueAttribute(): mixed
    {
        return self::castValue($this->value, $this->type);
    }

    /**
     * Используется и в репозитории, и снаружи (например, в Livewire-форме
     * для display).
     */
    public static function castValue(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }
        return match ($type) {
            self::TYPE_INT => (int) $raw,
            self::TYPE_FLOAT => (float) $raw,
            self::TYPE_BOOL => in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true),
            self::TYPE_JSON => json_decode($raw, true),
            default => $raw,
        };
    }

    /**
     * Сериализация PHP-значения в string-вид для хранения в `value`.
     */
    public static function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            self::TYPE_BOOL => $value ? '1' : '0',
            self::TYPE_JSON => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
