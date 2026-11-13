<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\MailboxConnector;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/**
 * 13.11.2026: imap.yandex.ru отбрасывает 3–7% новых подключений с нашего адреса — соединение ждало
 * 30 с и роняло задачу. Повторяем только обрыв подключения; отказ в авторизации не повторяем
 * (там свой разбор и backoff задачи).
 */
class MailboxConnectorRetryTest extends TestCase
{
    public function test_a_timed_out_connect_is_retried(): void
    {
        $e = new ConnectionFailedException('connection failed', 0,
            new \ErrorException('stream_socket_client(): Unable to connect to ssl://imap.yandex.ru:993 (Connection timed out)'));

        $this->assertTrue(MailboxConnector::isConnectTimeout($e));
        $this->assertTrue(MailboxConnector::isConnectTimeout(new ConnectionFailedException('connection setup failed')));
    }

    public function test_an_auth_failure_is_not_retried(): void
    {
        $e = new ConnectionFailedException('connection failed', 0, new AuthFailedException('failed to authenticate'));

        $this->assertFalse(MailboxConnector::isConnectTimeout($e));
        $this->assertFalse(MailboxConnector::isConnectTimeout(new ConnectionFailedException('Failed to set stream timeout')));
    }
}
