<?php

namespace App\Services\Quotes;

use App\Models\Request;
use Illuminate\Support\Collection;

/**
 * Чей это КП: заявки треда, в который его отправили, или заявки, чей номер 1С на нём стоит.
 *
 * Менеджер ведёт у клиента несколько заявок и отвечает в первый попавшийся тред:
 * M-2026-20451 (шкив, 1С 371400) получила ответом КП 371525 — это номер заявки
 * M-2026-1492 (ролики) того же клиента. КП записался на шкив, шкив ушёл в «КП
 * отправлено», строку КП прилепило к чужой позиции, а у M-2026-1492 КП нет.
 *
 * Переносим только в однозначном случае: у заявки треда свой номер 1С и он другой;
 * номер документа стоит ровно у одной другой заявке; это тот же клиент (организация
 * или e-mail); та заявка не закрыта. Заявка треда без номера 1С — не перенос: так
 * менеджер переотправляет старый КП в новой заявке клиента.
 */
class OutboundQuoteRequestResolver
{
    /** Заявка, которой принадлежит КП с этим номером, если это не заявка треда. */
    public function resolve(Request $thread, ?string $documentNumber): ?Request
    {
        $number = self::normalize($documentNumber);
        if ($number === null || ! self::threadHasOtherNumber($thread, $number)) {
            return null;
        }

        return $this->pick($thread, $number, Request::query()->whereKeyNot($thread->id)->whereRaw('trim(onec_number) = ?', [$number])->get());
    }

    /**
     * @param  Collection<int, Request>  $candidates  заявки с этим номером 1С, кроме заявки треда
     */
    public function pick(Request $thread, string $number, Collection $candidates): ?Request
    {
        if (! self::threadHasOtherNumber($thread, $number)) {
            return null;
        }
        $same = $candidates->filter(fn (Request $r) => $r->id !== $thread->id
            && self::normalize($r->onec_number) === $number
            && self::sameClient($thread, $r));
        if ($same->count() !== 1) {
            return null;
        }
        $target = $same->first();

        return $target->status?->isTerminal() ? null : $target;
    }

    private static function threadHasOtherNumber(Request $thread, string $number): bool
    {
        $own = self::normalize($thread->onec_number);

        return $own !== null && $own !== $number;
    }

    private static function sameClient(Request $a, Request $b): bool
    {
        if ($a->organization_id !== null && $a->organization_id === $b->organization_id) {
            return true;
        }
        $email = mb_strtolower(trim((string) $a->client_email));

        return $email !== '' && $email === mb_strtolower(trim((string) $b->client_email));
    }

    private static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
