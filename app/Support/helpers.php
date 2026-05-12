<?php

/**
 * Глобальные helper-функции приложения. Автозагружаются через
 * composer.json:autoload.files. См. AGENTS / CLAUDE.md — keep small,
 * только тривиальные обёртки.
 */

use App\Services\Settings\SettingsService;

if (! function_exists('app_setting')) {
    /**
     * Получить значение настройки из `app_settings`-таблицы (override
     * поверх config). Если override отсутствует — возвращает $default.
     *
     * Пример:
     *   $threshold = app_setting(
     *       'catalog.name_match.threshold',
     *       config('services.catalog_name_match.threshold')
     *   );
     */
    function app_setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}
