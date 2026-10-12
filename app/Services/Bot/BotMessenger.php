<?php

namespace App\Services\Bot;

/**
 * Отправка в мессенджер для бота заявок. Кнопки — строки из кнопок:
 * ['text' => …, 'data' => …] — кнопка с ответом боту,
 * ['text' => …, 'url' => …]  — ссылка,
 * ['text' => …, 'contact' => true] — «поделиться телефоном».
 */
interface BotMessenger
{
    /** @param  list<list<array{text: string, data?: string, url?: string, contact?: bool}>>  $buttons */
    public function sendText(string $chatId, string $html, array $buttons = []): bool;

    public function answerCallback(string $callbackId, ?string $notice = null): void;

    /** @return array{ok: bool, username: ?string, message: string} */
    public function me(): array;

    /** @return array{ok: bool, message: string} */
    public function connect(string $url, string $secret): array;

    /** @return array{ok: bool, message: string} */
    public function disconnect(string $url): array;
}
