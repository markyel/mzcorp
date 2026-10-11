<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\OutgoingSendRetryService;
use App\Services\Mail\SmtpReachabilityProbe;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Доступность SMTP ящиков знаем заранее (mail:smtp-health): 2026-10-11
 * «Отправить» висело минуту на мёртвом smtp.yandex.ru. Отправка читает
 * состояние; ошибка «недоступен» — временная, письмо в очередь/резерв.
 */
class SmtpReachabilityProbeTest extends TestCase
{
    private function freePort(bool $keepListening): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        if (! $keepListening) {
            fclose($server); // порт свободен — соединение отвергнут

            return [null, $port];
        }

        return [$server, $port];
    }

    public function test_open_port_is_up_and_send_does_not_wait(): void
    {
        [$server, $port] = $this->freePort(keepListening: true);
        $probe = new SmtpReachabilityProbe();

        $this->assertTrue($probe->check('127.0.0.1', $port));
        $this->assertFalse($probe->isKnownDown('127.0.0.1', $port));
        $probe->assertReachable('127.0.0.1', $port);

        fclose($server);
    }

    public function test_closed_port_is_known_down_and_fails_as_transient(): void
    {
        [, $port] = $this->freePort(keepListening: false);
        $probe = new SmtpReachabilityProbe();

        $this->assertFalse($probe->check('127.0.0.1', $port));
        $this->assertTrue($probe->isKnownDown('127.0.0.1', $port));

        try {
            $probe->assertReachable('127.0.0.1', $port);
            $this->fail('Ожидали TransportException');
        } catch (TransportException $e) {
            $this->assertTrue(app(OutgoingSendRetryService::class)->isTransient($e));
        }
    }

    public function test_unknown_state_is_checked_on_the_spot(): void
    {
        [, $port] = $this->freePort(keepListening: false);
        $probe = new SmtpReachabilityProbe();

        $this->expectException(TransportException::class);
        $probe->assertReachable('127.0.0.1', $port);
    }
}
