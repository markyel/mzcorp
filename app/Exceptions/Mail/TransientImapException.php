<?php

namespace App\Exceptions\Mail;

/**
 * Transient IMAP-сбой: server-side rate-limit, Yandex CLIENTBUG,
 * no-op COPYUID, временно недоступная папка и т.п.
 *
 * Маркер для caller'ов (RouteMailToManagerJob, RequestItemPersister) —
 * сигнал что операцию имеет смысл повторить через backoff. На non-transient
 * сбоях (нет mailbox / нет manager / некорректные данные) MailFolderRouter
 * возвращает null без throw.
 */
class TransientImapException extends \RuntimeException
{
}
