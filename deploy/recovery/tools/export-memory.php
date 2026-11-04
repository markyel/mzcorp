<?php
// Копия личных заметок в репозиторий с вырезанными адресами, IP, реквизитами и ссылками с ключами.
[$_, $src, $dst] = $argv;
$allowed = ['info@myzip.ru', 'noreply@myzip.ru', 'rfq@mzcorp.ru'];
@mkdir($dst, 0755, true);
foreach (glob($dst.'/*.md') as $old) {
    if (basename($old) !== 'README.md') {
        unlink($old);
    }
}
$counts = [];
$rules = [
    'адреса' => ['/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', fn ($m) => in_array(strtolower($m[0]), $allowed, true) ? $m[0] : '<адрес>'],
    'IP' => ['/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', fn ($m) => '<IP>'],
    'ссылки' => ['/(getxfile\.php\?id=)[A-Za-z0-9-]+/', fn ($m) => $m[1].'<id>'],
    'ключи в ссылках' => ['/(https?:\/\/[^\s)`"]*[?&](?:token|key|secret)=)[^\s)`"&]+/', fn ($m) => $m[1].'<скрыто>'],
    'организации' => ['/\b(?:ООО|АО|ПАО|ЗАО|ИП)\s*[«"][^»"\n]{1,60}[»"]/u', fn ($m) => str_contains($m[0], 'Мой Лифт') || str_contains($m[0], 'Мой ЗиП') ? $m[0] : '<организация>'],
    'ИНН' => ['/(ИНН\s*:?\s*)\d{10,12}/u', fn ($m) => $m[1].'<ИНН>'],
    'телефоны' => ['/\+7[\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}/', fn ($m) => '<телефон>'],
    'аккаунты' => ['/[a-z0-9-]+.[a-z0-9-]+.workers.dev|cloud-[a-z0-9]+/', fn ($m) => '<аккаунт>'],
    'служебное' => ['/^(\s*originSessionId:).*$/m', fn ($m) => $m[1].' <скрыто>'],
];
foreach (glob($src.'/*.md') as $path) {
    $text = file_get_contents($path);
    foreach ($rules as $key => [$pattern, $fn]) {
        $text = preg_replace_callback($pattern, $fn, $text, -1, $n);
        $counts[$key] = ($counts[$key] ?? 0) + $n;
    }
    file_put_contents($dst.'/'.basename($path), str_replace("\r\n", "\n", $text));
}
echo json_encode($counts, JSON_UNESCAPED_UNICODE), PHP_EOL;
