<?php

namespace App\Exceptions\Mail;

use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;

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
    /**
     * Временный сбой на стороне Яндекса, который задача переживает повтором, — или null.
     *  - «NO [UNAVAILABLE] … internal server error / try again later» на AUTHENTICATE, APPEND, SELECT;
     *  - пустой ответ (обрыв соединения) на AUTHENTICATE.
     * Отказ во входе («NO [AUTHENTICATIONFAILED] … invalid credentials») — не временный: токен/пароль.
     * За 13–26.10.2026 в worker.log 1450 «NO [UNAVAILABLE] AUTHENTICATE» против одного AUTHENTICATIONFAILED,
     * а в laravel.log они шли ERROR'ом «failed to authenticate» и выглядели как проблема с паролем ящика.
     *
     * @return ?string причина одной строкой для лога
     */
    public static function yandexOutage(\Throwable $e): ?string
    {
        $chain = [];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $chain[] = $x;
        }
        $text = implode("\n", array_map(fn (\Throwable $x) => $x->getMessage(), $chain));

        if (str_contains($text, '[AUTHENTICATIONFAILED]')) {
            return null;
        }
        if (preg_match('/NO \[UNAVAILABLE\][^\r\n]*/', $text, $m)) {
            return trim((string) preg_replace('/\s*sc=\S+/', '', $m[0]));
        }

        $authFailed = false;
        $emptyResponse = false;
        foreach ($chain as $x) {
            $authFailed = $authFailed || $x instanceof AuthFailedException;
            $emptyResponse = $emptyResponse || ($x instanceof ImapRuntimeException && $x->getMessage() === 'empty response');
        }

        return $authFailed && $emptyResponse ? 'empty response to AUTHENTICATE' : null;
    }
}
