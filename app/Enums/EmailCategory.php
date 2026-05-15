<?php

namespace App\Enums;

/**
 * Категория письма (единственный AI-классификатор, gpt-4o, Phase 1.8c).
 *
 * Решение «создать Request» в MailRouter принимается по этой категории.
 * Второй уровень (gpt-4o-mini, EmailClassification) удалён — системно
 * ошибался на «Прошу счёт MNNNN» → accounting.
 *
 *   client_request — клиент просит у нас запчасти / КП. Только эта категория
 *                     запускает IncomingMailProcessor + RequestItemParsingService.
 *   thread_reply   — клиент отвечает в существующем треде (Re:/Fwd: + цитата
 *                     от нас). Не создаём новый Request, прикрепляем к существующему.
 *   irrelevant     — всё прочее (наши же исходящие, поставщики, авто-ответы,
 *                     newsletter, спам, бухгалтерия, услуги без ТМЦ, internal).
 */
enum EmailCategory: string
{
    case ClientRequest = 'client_request';
    case ThreadReply = 'thread_reply';
    case Irrelevant = 'irrelevant';

    public function label(): string
    {
        return match ($this) {
            self::ClientRequest => 'Заявка клиента',
            self::ThreadReply => 'Ответ в треде',
            self::Irrelevant => 'Не клиентская',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
