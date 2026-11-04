<?php
// Проверка подключённых ящиков: токен обновляется, IMAP пускает, папки видны. Только EXAMINE/STATUS, токены не выводятся.
require '/var/www/mzcorp/vendor/autoload.php';
$app = require '/var/www/mzcorp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Mailbox;

function ask($s, string $tag, string $c): array {
    fwrite($s, "$tag $c\r\n");
    $rows = [];
    while (($l = fgets($s)) !== false) {
        if (str_starts_with($l, "$tag ")) { return [str_starts_with($l, "$tag OK"), $rows, trim($l)]; }
        $rows[] = trim($l);
    }
    return [false, $rows, 'обрыв'];
}

foreach (Mailbox::with('owner.roles')->orderBy('id')->get() as $m) {
    $hasToken = (bool) $m->accessToken();
    echo $m->id, ' ', $m->email, ' active=', $m->is_active ? 'да' : 'нет', ' токен=', $hasToken ? 'да' : 'нет',
        ' роль=', $m->owner?->roles->pluck('name')->join(',') ?: '-', PHP_EOL;
    if (! $hasToken || $m->id === 1) { continue; }
    try {
        app(App\Services\Mail\YandexOAuthService::class)->ensureFreshToken($m);
        $m->refresh();
        $s = stream_socket_client('ssl://imap.yandex.ru:993', $en, $es, 20);
        stream_set_timeout($s, 60);
        fgets($s);
        [$ok, , $r] = ask($s, 'a1', 'AUTHENTICATE XOAUTH2 '.base64_encode("user={$m->imap_username}\x01auth=Bearer {$m->accessToken()}\x01\x01"));
        echo '   IMAP: ', $ok ? 'вход выполнен' : 'ОТКАЗ '.substr($r, 0, 80), PHP_EOL;
        if (! $ok) { continue; }
        [, $list] = ask($s, 'a2', 'XLIST "" "*"');
        foreach ($list as $l) {
            if (preg_match('/^\* XLIST \(([^)]*)\) "(.)" "?(.*?)"?$/', $l, $x)) {
                [, $st] = ask($s, 'a3', 'STATUS "'.$x[3].'" (MESSAGES UNSEEN)');
                preg_match('/MESSAGES (\d+)/', $st[0] ?? '', $a);
                preg_match('/UNSEEN (\d+)/', $st[0] ?? '', $b);
                $special = preg_match('/\\\\(Sent|Inbox|Drafts|Trash|Spam|Junk)/i', $x[1], $f) ? $f[1] : '';
                echo '   ', $x[3], $special ? " [$special]" : '', ' писем=', $a[1] ?? '?', ' непрочитано=', $b[1] ?? '?', PHP_EOL;
            }
        }
        ask($s, 'z', 'LOGOUT');
        fclose($s);
    } catch (Throwable $e) {
        echo '   ОШИБКА: ', substr($e->getMessage(), 0, 150), PHP_EOL;
    }
}
