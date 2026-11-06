<?php

namespace App\Support;

/**
 * Опции HTTP-клиента для запросов к Telegram Bot API.
 *
 * С сервера Reg.ru api.telegram.org недоступен (обрыв по таймауту, 06.11.2026),
 * поэтому запросы идут через SOCKS-туннель на зарубежный сервер
 * (`services.telegram.proxy`, напр. socks5h://127.0.0.1:1080). Пусто — напрямую.
 * TLS до Telegram сквозной: ключ бота туннель не видит.
 */
final class TelegramHttp
{
    /** @return array<string, mixed> */
    public static function options(): array
    {
        $proxy = trim((string) config('services.telegram.proxy', ''));

        return $proxy !== '' ? ['proxy' => $proxy] : [];
    }
}
