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
 *   post_sale      — постпродажная переписка по УЖЕ успешно закрытой (closed_won)
 *                     сделке: сроки/статус доставки, наличие сертификатов,
 *                     закрывающие документы (УПД, счёт-фактура, акт). Легитимна,
 *                     но НЕ создаёт новую заявку — прикрепляется к закрытой заявке,
 *                     менеджеру поднимается алерт. Если у клиента нет подходящей
 *                     closed_won заявки — MailRouter трактует письмо как
 *                     client_request (новая заявка).
 *   irrelevant     — всё прочее (наши же исходящие, поставщики, авто-ответы,
 *                     newsletter, спам, бухгалтерия, услуги без ТМЦ, internal).
 */
enum EmailCategory: string
{
    case ClientRequest = 'client_request';
    case ThreadReply = 'thread_reply';
    case PostSale = 'post_sale';
    case Irrelevant = 'irrelevant';
    // Переписка с поставщиком: ответ в треде, помеченном как наш запрос
    // расценки поставщику (SupplierInquiry). Не создаёт клиентскую заявку —
    // прикрепляется к запросу поставщику. Ставится SupplierInquiryService.
    case SupplierReply = 'supplier_reply';

    public function label(): string
    {
        return match ($this) {
            self::ClientRequest => 'Заявка клиента',
            self::ThreadReply => 'Ответ в треде',
            self::PostSale => 'Постпродажная переписка',
            self::Irrelevant => 'Не клиентская',
            self::SupplierReply => 'Переписка с поставщиком',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
