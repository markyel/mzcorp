<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Сверка «это одно и то же физическое письмо, доставленное в разные
 * ящики» — для cross-mailbox дедупа по Message-ID.
 *
 * Раньше дедуп считал совпадение Message-ID достаточным признаком копии.
 * Это неверно для Outlook/Exchange: клиент переиспользует Thread-Index в
 * качестве Message-ID, из-за чего исходное письмо и пересланный follow-up
 * («FW: …») получают ОДИН Message-ID при разных subject и Date. Дедуп
 * ложно глотал follow-up как копию — письмо не уезжало в папку менеджера
 * (пайплайн обрывался) и пряталось из треда заявки (кейс M-2026-5907:
 * «FW: Поручни» с уточнением «Может быть 76 мм» завис в info@/INBOX).
 *
 * Подлинная копия — это APPEND оригинала в личный ящик менеджера
 * (DeliverToManagerInboxJob) или CC коллеге: у неё совпадают и subject,
 * и Date-заголовок. Поэтому помимо Message-ID сверяем subject + sent_at.
 */
final class CrossMailboxCopyMatcher
{
    /**
     * Одно ли это физическое письмо. Предполагается, что message_id у
     * $a и $b уже совпал (вызывается после WHERE message_id = …).
     */
    public static function isSamePhysicalMessage(EmailMessage $a, EmailMessage $b): bool
    {
        if (trim((string) $a->subject) !== trim((string) $b->subject)) {
            return false;
        }

        $sentA = $a->sent_at;
        $sentB = $b->sent_at;

        // Оба без Date-заголовка — допускаем (редкие письма без Date).
        // Один с датой, другой без — это разные письма.
        if ($sentA === null || $sentB === null) {
            return $sentA === null && $sentB === null;
        }

        return $sentA->equalTo($sentB);
    }
}
