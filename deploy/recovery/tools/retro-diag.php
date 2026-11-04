<?php
// Диагностика сборки: куда деваются документы и что попало в переписку с поставщиками. Только счётчики.
require '/var/www/mzcorp/vendor/autoload.php';
$app = require '/var/www/mzcorp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
ini_set('memory_limit', '-1');

use App\Enums\Role;
use App\Models\User;
use App\Services\Retro\RetroAssembler;
use App\Services\Retro\RetroIndex;
use App\Services\Retro\RetroMailParser as P;

$index = new RetroIndex(config('retro.index_path'), config('retro.year'), config('retro.max_legacy_number'));
$rows = $index->load();
$handlers = User::query()->role(Role::requestHandlerRoles())->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
$result = (new RetroAssembler(config('retro'), $handlers, time()))->assemble($rows);
$rows = $result['rows'];

$supplierOf = [];
$planOf = [];
foreach ($result['requests'] as $n => $r) {
    foreach ($r['messages'] as $m) {
        $supplierOf[$m['i']] = $m['supplier'];
        $planOf[$m['i']] = $n;
    }
}

$docs = ['quote' => ['all' => [], 'client' => [], 'supplier' => [], 'nonum' => [], 'before' => []], 'invoice' => ['all' => [], 'client' => [], 'supplier' => [], 'nonum' => [], 'before' => []]];
$start = strtotime('2026-05-25');
$supplierDocTo = [];
$supplierWithDoc = 0;
foreach ($rows as $i => $row) {
    if (! $row['outbound']) continue;
    foreach ($row['names'] as $name) {
        $d = P::documentFromFilename($name);
        if (! $d) continue;
        $t = $d['type'];
        $docs[$t]['all'][$d['number']] = true;
        if (($row['ts'] ?? 0) < $start) { $docs[$t]['before'][$d['number']] = true; }
        if (! isset($planOf[$i])) { $docs[$t]['nonum'][$d['number']] = true; continue; }
        if ($supplierOf[$i]) { $docs[$t]['supplier'][$d['number']] = true; $supplierWithDoc++; continue; }
        $docs[$t]['client'][$d['number']] = true;
    }
}
foreach ($docs as $t => $set) {
    echo $t, ': всего ', count($set['all']), ', до 25 мая ', count($set['before']), ', в клиентских цепочках ', count($set['client']),
        ', в цепочках поставщика ', count($set['supplier']), ', без номера заявки ', count($set['nonum']), PHP_EOL;
}
echo 'Писем с документом в цепочке поставщика: ', $supplierWithDoc, PHP_EOL;

// Один документ в нескольких заявках и заявки с документом.
$perReq = ['quote' => [], 'invoice' => []];
$reqCnt = 0; $sumQ = 0; $sumI = 0;
foreach ($result['requests'] as $r) { $sumQ += count($r['quotes']); $sumI += count($r['invoices']); foreach ($r['quotes'] as $d) { $perReq['quote'][$d['number']][] = $r['number']; } foreach ($r['invoices'] as $d) { $perReq['invoice'][$d['number']][] = $r['number']; } }
echo 'Строк КП ', $sumQ, ' разных ', count($perReq['quote']), '; строк счетов ', $sumI, ' разных ', count($perReq['invoice']), PHP_EOL;

// Что за цепочки поставщика: кто писатель, есть ли там клиентский домен.
$counts = ['всего' => 0, 'внутр. копия заявки' => 0];
$domains = [];
$reqWithSupplier = 0;
foreach ($result['requests'] as $r) {
    $has = false;
    foreach ($r['messages'] as $m) {
        if (! $m['supplier']) continue;
        $has = true;
        $counts['всего']++;
        $row = $rows[$m['i']];
        foreach (array_merge($row['recipients'], [$row['from_email']]) as $a) {
            $dom = substr(strrchr($a, '@') ?: '', 1);
            if ($dom !== '' && ! in_array($dom, config('retro.internal_domains'), true)) {
                $domains[$dom] = ($domains[$dom] ?? 0) + 1;
            }
        }
    }
    $reqWithSupplier += $has ? 1 : 0;
}
arsort($domains);
echo 'Заявок с такой цепочкой: ', $reqWithSupplier, ', разных доменов: ', count($domains), PHP_EOL;
$top = array_slice($domains, 0, 15, true);
echo 'Писем на домен, первые 15: ', implode(' ', array_values($top)), PHP_EOL;
$one = count(array_filter($domains, fn ($v) => $v <= 2));
echo 'Доменов с 1-2 письмами: ', $one, PHP_EOL;
