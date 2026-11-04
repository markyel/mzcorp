<?php

namespace App\Services\Retro;

use App\Models\Mailbox;
use App\Services\Mail\YandexOAuthService;
use RuntimeException;

/**
 * Соединение с ящиком только на чтение. Пропускает лишь команды, которые не
 * могут изменить письма и флаги: папки через EXAMINE, письма через BODY.PEEK.
 * webklex здесь не подходит: его чтение на Яндексе может ставить \Seen.
 */
class RetroImapReader
{
    private const HOST = 'ssl://imap.yandex.ru:993';

    private const ALLOWED = ['AUTHENTICATE XOAUTH2 ', 'XLIST ', 'STATUS ', 'EXAMINE ', 'UID SEARCH ', 'UID FETCH ', 'LOGOUT'];

    /** @var resource|null */
    private $socket = null;

    private int $tag = 0;

    private string $user;

    private string $token;

    public function __construct(Mailbox $mailbox)
    {
        app(YandexOAuthService::class)->ensureFreshToken($mailbox);
        $mailbox->refresh();
        $this->user = (string) $mailbox->imap_username;
        $this->token = (string) $mailbox->accessToken();
        if ($this->token === '') {
            throw new RuntimeException("У ящика {$mailbox->email} нет токена");
        }
        $this->connect();
    }

    /** Новое соединение. Яндекс может оборвать и вход, поэтому несколько попыток. */
    public function connect(): void
    {
        for ($try = 1; ; $try++) {
            try {
                $this->open();

                return;
            } catch (RuntimeException $e) {
                if ($try >= 5) {
                    throw $e;
                }
                sleep(15 * $try);
            }
        }
    }

    private function open(): void
    {
        $this->close();
        $socket = @stream_socket_client(self::HOST, $errno, $error, 20);
        if (! $socket) {
            throw new RuntimeException("IMAP connect: $error");
        }
        stream_set_timeout($socket, 180);
        fgets($socket);
        $this->socket = $socket;
        [$ok] = $this->command('AUTHENTICATE XOAUTH2 '.base64_encode("user={$this->user}\x01auth=Bearer {$this->token}\x01\x01"));
        if (! $ok) {
            throw new RuntimeException('IMAP auth failed');
        }
    }

    /**
     * @return array{0:bool, 1:list<array{0:string, 1:list<string>}>, 2:string} Успех, строки ответа [текст, литералы], итоговая строка.
     */
    public function command(string $command): array
    {
        self::assertReadOnly($command);
        $tag = 'r'.(++$this->tag);
        fwrite($this->socket, "$tag $command\r\n");
        $rows = [];
        while (true) {
            $line = fgets($this->socket);
            if ($line === false) {
                // Вход в журнал не пишем: в команде токен.
                throw new RuntimeException('Соединение оборвалось на: '.(str_starts_with($command, 'AUTHENTICATE') ? 'вход' : substr($command, 0, 40)));
            }
            if (str_starts_with($line, "$tag ")) {
                return [str_starts_with($line, "$tag OK"), $rows, trim($line)];
            }
            $text = $line;
            $all = $line;
            $literals = [];
            while (preg_match('/\{(\d+)\}\r\n$/', $text, $m)) {
                $size = (int) $m[1];
                $buffer = '';
                while (strlen($buffer) < $size) {
                    $chunk = fread($this->socket, min(65536, $size - strlen($buffer)));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Обрыв при чтении письма');
                    }
                    $buffer .= $chunk;
                }
                $literals[] = $buffer;
                // Короткие литералы (имена файлов в BODYSTRUCTURE) остаются в тексте строки.
                if ($size < 2000) {
                    $all .= '"'.str_replace('"', ' ', $buffer).'" ';
                }
                $text = fgets($this->socket);
                if ($text === false) {
                    throw new RuntimeException('Обрыв после письма');
                }
                $all .= $text;
            }
            $rows[] = [$all, $literals];
        }
    }

    /** Папки ящика: путь → флаги. */
    public function folders(): array
    {
        [, $rows] = $this->command('XLIST "" "*"');
        $out = [];
        foreach ($rows as [$line]) {
            if (preg_match('/^\* XLIST \(([^)]*)\) "(.)" "?(.*?)"?\s*$/', trim($line), $x)) {
                $out[$x[3]] = $x[1];
            }
        }

        return $out;
    }

    /** @return array{uidvalidity:?int, uidnext:?int} */
    public function examine(string $folder): array
    {
        [$ok, $rows, $response] = $this->command('EXAMINE "'.$folder.'"');
        if (! $ok) {
            throw new RuntimeException("EXAMINE $folder: $response");
        }
        $text = implode(' ', array_column($rows, 0));

        return [
            'uidvalidity' => preg_match('/UIDVALIDITY (\d+)/', $text, $v) ? (int) $v[1] : null,
            'uidnext' => preg_match('/UIDNEXT (\d+)/', $text, $n) ? (int) $n[1] : null,
        ];
    }

    /** @return list<int> */
    public function search(string $criteria): array
    {
        [, $rows] = $this->command('UID SEARCH '.$criteria);
        $uids = [];
        foreach ($rows as [$line]) {
            if (str_starts_with($line, '* SEARCH')) {
                array_push($uids, ...array_map('intval', preg_split('/\s+/', trim(substr($line, 8)), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }

        return $uids;
    }

    /**
     * Пачка писем с повторами. Обрыв связи лечится новым соединением. На отказ
     * «NO [UNAVAILABLE]» Яндекс отвечает всей пачке из-за одного письма: пачка
     * делится пополам, а письмо, которое не отдаётся и в одиночку, пропускается.
     *
     * @param  list<int>  $uids
     * @param  callable(string):void|null  $onRetry
     * @return list<array{0:string, 1:list<string>}>
     */
    public function fetchResilient(string $folder, array $uids, string $items, ?callable $onRetry = null): array
    {
        for ($try = 1; $try <= 6; $try++) {
            try {
                [$ok, $rows, $response] = $this->command('UID FETCH '.implode(',', $uids).' ('.$items.')');
                if ($ok) {
                    return $rows;
                }
                if ($onRetry) {
                    $onRetry("отказ, $folder, писем ".count($uids).": ".substr($response, 0, 60));
                }
                if ($try >= 2) {
                    break;
                }
                sleep(5);
            } catch (RuntimeException $e) {
                if ($try >= 6) {
                    throw $e;
                }
                if ($onRetry) {
                    $onRetry("повтор $try, $folder: ".$e->getMessage());
                }
                sleep(20 * $try);
                $this->connect();
                $this->examine($folder);
            }
        }
        if (count($uids) === 1) {
            if ($onRetry) {
                $onRetry("письмо не отдаётся, пропущено: $folder, UID {$uids[0]}");
            }

            return [];
        }
        $half = (int) ceil(count($uids) / 2);

        return array_merge(
            $this->fetchResilient($folder, array_slice($uids, 0, $half), $items, $onRetry),
            $this->fetchResilient($folder, array_slice($uids, $half), $items, $onRetry),
        );
    }

    public function close(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, "z LOGOUT\r\n");
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Команда, которая может изменить почту, здесь не выполняется вообще. */
    public static function assertReadOnly(string $command): void
    {
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($command, $prefix)) {
                if (str_starts_with($command, 'UID FETCH ') && preg_match('/\bBODY\[|\bRFC822\b|\bRFC822\.TEXT\b/i', $command)) {
                    throw new RuntimeException('Чтение письма возможно только через BODY.PEEK');
                }

                return;
            }
        }
        throw new RuntimeException('Команда запрещена для ретрозагрузки: '.strtok($command, ' '));
    }
}
