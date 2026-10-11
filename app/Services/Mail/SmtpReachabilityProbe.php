<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Доступность SMTP-серверов ящиков: фоновое состояние + быстрая проверка.
 *
 * 2026-10-11 smtp.yandex.ru не отвечал, и «Отправить» висело больше минуты:
 * Symfony ждёт соединения default_socket_timeout (60 с). Таймаут самой
 * SMTP-сессии сокращать нельзя — после DATA сервер может долго проверять
 * большое письмо, обрыв там = риск дубля клиенту. Поэтому доступность
 * знаем заранее: mail:smtp-health раз в минуту проверяет TCP-соединение
 * и хранит состояние. Отправка его только читает — менеджер не ждёт, а
 * при «недоступен» письмо сразу идёт резервным каналом (OutgoingMailSender)
 * или в очередь повторов.
 */
class SmtpReachabilityProbe
{
    /** Состояние живёт дольше интервала крона — пропуск прогона не обнуляет знание. */
    private const STATE_TTL_SECONDS = 180;

    /** Сервер известен как недоступный (последняя проверка не дождалась соединения). */
    public function isKnownDown(string $host, int $port): bool
    {
        $state = Cache::get($this->key($host, $port));

        return is_array($state) && ($state['up'] ?? true) === false;
    }

    /**
     * Перед отправкой: знаем, что лежит, — сразу ошибка; знаем, что жив, —
     * не тратим время; не знаем — проверяем сами.
     *
     * @throws TransportException «Connection could not be established …» —
     *         OutgoingSendRetryService считает это временным сбоем (письмо в очередь).
     */
    public function assertReachable(string $host, int $port): void
    {
        $state = Cache::get($this->key($host, $port));
        if (is_array($state)) {
            if (($state['up'] ?? true) === false) {
                throw $this->unreachable($host, $port, 'недоступен с '.($state['down_since'] ?? '?'));
            }

            return;
        }

        if (! $this->check($host, $port)) {
            throw $this->unreachable($host, $port, 'не отвечает');
        }
    }

    /** Проверить TCP-соединение и запомнить; смену состояния — в лог. */
    public function check(string $host, int $port): bool
    {
        $timeout = max(1, (int) config('services.mail_outbound.connect_probe_seconds', 8));
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $up = $socket !== false;
        if ($up) {
            fclose($socket);
        }

        $key = $this->key($host, $port);
        $prev = Cache::get($key);
        $wasUp = is_array($prev) ? ($prev['up'] ?? true) : null;
        $now = now()->toDateTimeString();

        Cache::put($key, [
            'up' => $up,
            'checked_at' => $now,
            'down_since' => $up ? null : (($wasUp === false) ? ($prev['down_since'] ?? $now) : $now),
        ], self::STATE_TTL_SECONDS);

        if ($wasUp !== null && $wasUp !== $up) {
            Log::warning($up ? 'SMTP health: back up' : 'SMTP health: down', [
                'host' => $host,
                'port' => $port,
                'down_since' => $prev['down_since'] ?? null,
                'error' => $up ? null : ($errstr ?: 'timeout'),
            ]);
        }

        return $up;
    }

    private function key(string $host, int $port): string
    {
        return 'smtp-health:'.strtolower($host).':'.$port;
    }

    private function unreachable(string $host, int $port, string $why): TransportException
    {
        return new TransportException(sprintf('Connection could not be established with host "%s:%d": %s', $host, $port, $why));
    }
}
