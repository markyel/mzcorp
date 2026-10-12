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

    /** Файл (КП, счёт) с подписью. */
    public function sendDocument(string $chatId, string $bytes, string $filename, ?string $captionHtml = null): bool;

    /**
     * Скачать фото/файл клиента (см. BotUpdate::$files).
     *
     * @param  array{kind: string, ref: string, name: ?string, mime: ?string, size: ?int}  $file
     * @return array{bytes: string, name: string, mime: ?string}|null
     */
    public function downloadFile(array $file): ?array;

    public function answerCallback(string $callbackId, ?string $notice = null): void;

    /** @return array{ok: bool, username: ?string, message: string} */
    public function me(): array;

    /** @return array{ok: bool, message: string} */
    public function connect(string $url, string $secret): array;

    /** @return array{ok: bool, message: string} */
    public function disconnect(string $url): array;
}
