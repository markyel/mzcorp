<?php
// Какие суммы можно взять из текста PDF: выборка КП и счетов. Печатает только счётчики и формы строк с цифрами, заменёнными на 9.
require '/var/www/mzcorp/vendor/autoload.php';
$app = require '/var/www/mzcorp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$size = (int) ($argv[1] ?? 300);
foreach (['outbound_quotation_full', 'outbound_invoice'] as $type) {
    $rows = DB::table('outbound_quotes as q')->join('email_attachments as a', 'a.id', '=', 'q.email_attachment_id')
        ->where('q.document_type', $type)->whereRaw('a.filename ilike ?', ['%.pdf'])
        ->orderByRaw('md5(q.id::text)')->limit($size)->get(['a.file_path', 'a.disk']);
    $stats = ['всего' => 0, 'с текстом' => 0];
    $labels = [];
    foreach ($rows as $r) {
        $stats['всего']++;
        $path = Storage::disk($r->disk)->path($r->file_path);
        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');
        if (mb_strlen(trim($text)) > 50) {
            $stats['с текстом']++;
        }
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (preg_match('/(итог|всего|к оплате|в том числе|ндс)/iu', $line) && preg_match('/\d/', $line)) {
                $shape = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\d/', '9', $line)));
                $shape = (string) preg_replace('/9[9 ,.]*9/', '#', $shape);
                $labels[mb_substr($shape, 0, 60)] = ($labels[mb_substr($shape, 0, 60)] ?? 0) + 1;
            }
        }
    }
    arsort($labels);
    echo '=== ', $type, ' ', json_encode($stats, JSON_UNESCAPED_UNICODE), PHP_EOL;
    foreach (array_slice($labels, 0, 12, true) as $shape => $n) {
        echo str_pad((string) $n, 5), $shape, PHP_EOL;
    }
}
