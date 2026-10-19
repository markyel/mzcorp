<?php

namespace App\Support;

/**
 * «Назад» туда, откуда пришли: страница, с которой открыли эту (Referer, иначе предыдущий адрес
 * сессии), если она наша и не эта же; иначе — запасной адрес. Считать один раз при открытии
 * страницы (mount) и хранить в компоненте: в запросах Livewire Referer — сама страница.
 * Кейс: из QuoteParts → «Поставщики» открыли карточку поставщика, «← Поставщики» вело в реестр.
 */
class BackUrl
{
    public static function from(string $fallback): string
    {
        $current = request()->url();
        foreach ([request()->headers->get('referer'), url()->previous()] as $candidate) {
            if (self::usable($candidate, $current)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private static function usable(?string $url, string $current): bool
    {
        if ($url === null || $url === '') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === request()->getHost()
            && strtok($url, '?') !== $current
            && ! str_starts_with($path, '/livewire')
            && ! str_starts_with($path, '/login');
    }

    /** Подпись ссылки: откуда пришли — понятным словом. */
    public static function label(string $url, string $default): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            str_starts_with($path, '/dashboard/quoteparts') => '← QuoteParts',
            str_starts_with($path, '/dashboard/procurement') => '← Снабжение',
            str_starts_with($path, '/dashboard/requests/') => '← Заявка',
            str_starts_with($path, '/dashboard/catalog') => '← Каталог',
            default => $default,
        };
    }
}
