<?php

namespace App\Enums;

/**
 * Категория письма по новой классификации Phase 1.8c (drop-in из LazyLift Flow 1).
 *
 * Старый App\Enums\EmailClassification оставлен для обратной совместимости
 * с MailRoutingRule.match_mode='ai_classified', но триггером для создания
 * Request теперь служит EmailCategory::ClientRequest.
 *
 *   client_request — клиент просит у нас запчасти / КП. Только эта категория
 *                     запускает RequestItemParsingService.
 *   thread_reply   — клиент отвечает в существующем треде (Re:/Fwd: + цитата
 *                     от нас). Не создаём новый Request, прикрепляем к существующему.
 *   irrelevant     — всё прочее (наши же исходящие, поставщики, авто-ответы,
 *                     newsletter, спам, услуги без ТМЦ, internal).
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
