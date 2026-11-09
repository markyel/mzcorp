<?php
require '/var/www/quoteparts/vendor/autoload.php';
$app = require '/var/www/quoteparts/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$since = now()->subMinutes((int) ($argv[1] ?? 30));
echo "== QuoteParts since ", $since->format('H:i'), " (UTC)", PHP_EOL;
echo "rfqs: ", DB::table('rfqs')->where('created_at','>=',$since)->count(), " · queue: ", DB::table('jobs')->count(),
     " · failed: ", json_encode(DB::table('failed_jobs')->where('failed_at','>=',$since)->get(['payload','exception'])->map(fn ($j) => [json_decode($j->payload)->displayName ?? '?', mb_substr(strtok($j->exception, "\n"), 0, 160)])->groupBy(0)->map(fn ($g) => [count($g), $g[0][1]]), JSON_UNESCAPED_UNICODE), PHP_EOL;
