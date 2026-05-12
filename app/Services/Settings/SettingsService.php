<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Источник правды для настроек, редактируемых через UI «Настройки».
 *
 * Используется как override поверх config(): код вызывает
 *   app_setting('catalog.name_match.threshold', config('services.catalog_name_match.threshold'))
 * Если в БД есть запись — отдаётся она. Если нет — fallback из config (env).
 *
 * Кэш на 5 минут — чтобы каждый запрос не дёргал БД. Cache::forget при
 * любом set().
 */
class SettingsService
{
    private const CACHE_KEY = 'app_settings:all';
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Получить значение настройки, приведённое к нужному типу.
     * Если ключа в БД нет — вернуть $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (! array_key_exists($key, $all)) {
            return $default;
        }
        $entry = $all[$key];

        return AppSetting::castValue($entry['value'], $entry['type']);
    }

    /**
     * Записать значение. Сериализуется в string согласно type.
     * После записи кэш сбрасывается.
     */
    public function set(string $key, mixed $value, string $type, ?int $userId = null, ?string $description = null): AppSetting
    {
        if (! in_array($type, AppSetting::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown type: {$type}");
        }

        $raw = AppSetting::serializeValue($value, $type);

        $setting = AppSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $raw,
                'type' => $type,
                'description' => $description,
                'updated_by_user_id' => $userId,
            ],
        );

        $this->forget();

        return $setting;
    }

    /**
     * Удалить override (вернуться к defaults из config/env).
     */
    public function unset(string $key): bool
    {
        $deleted = AppSetting::where('key', $key)->delete() > 0;
        if ($deleted) {
            $this->forget();
        }

        return $deleted;
    }

    /**
     * Сбросить кэш. Вызывается при любом set/unset.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Все настройки (key → {value, type, description}). Кэшируется 5 мин.
     *
     * @return array<string, array{value: ?string, type: string, description: ?string}>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            try {
                return AppSetting::query()
                    ->get(['key', 'value', 'type', 'description'])
                    ->keyBy('key')
                    ->map(fn ($s) => [
                        'value' => $s->value,
                        'type' => $s->type,
                        'description' => $s->description,
                    ])
                    ->all();
            } catch (\Throwable $e) {
                // Таблица ещё не существует (миграция не накатана) — возвращаем
                // пустоту, чтобы вся система пошла на config-defaults без падения.
                return [];
            }
        });
    }
}
