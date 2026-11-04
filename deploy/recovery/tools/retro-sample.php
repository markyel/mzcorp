<?php
// Выборка 30 заявок для проверки людьми и автоматическая проверка их карточек на базе MyLift_retro.
// Печатает только номера и счётчики, без адресов и текста писем.
require '/var/www/mzcorp/vendor/autoload.php';
$app = require '/var/www/mzcorp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$c = config('database.default');
config(["database.connections.$c.database" => 'MyLift_retro', 'session.driver' => 'array', 'app.debug' => false]);
DB::purge($c);

$pick = function (string $label, string $where, int $n) {
    $rows = DB::select("select id, internal_code from requests r where $where order by md5(internal_code) limit $n");

    return array_map(fn ($r) => [$label, $r->id, $r->internal_code], $rows);
};
$sample = array_merge(
    $pick('в работе', "status='in_progress'", 5),
    $pick('КП отправлено', "status='quoted'", 6),
    $pick('счёт выставлен', "status='invoiced'", 5),
    $pick('закрыта', "status='closed_lost' and peak_status='invoiced'", 3),
    $pick('закрыта', "status='closed_lost' and peak_status='quoted'", 3),
    $pick('закрыта', "status='closed_lost' and peak_status is null", 2),
    $pick('с поставщиками', "exists (select 1 from email_messages e where e.related_request_id=r.id and e.category='supplier_reply') and status<>'closed_lost'", 3),
    $pick('май, запуск', "created_at < '2026-05-29'", 2),
    $pick('Ежов', "assigned_user_id=8", 1),
);

$admin = User::find(1);
$bad = 0;
foreach ($sample as [$label, $id, $code]) {
    Auth::guard('web')->setUser($admin);
    $t = microtime(true);
    $response = $kernel->handle(HttpRequest::create('/dashboard/requests/'.$id, 'GET'));
    $html = html_entity_decode((string) $response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $primary = DB::select("select body_plain from email_messages where related_request_id=? and not jsonb_exists(detected_artifacts,'cross_mailbox_copy_of') and coalesce(category,'')<>'supplier_reply' and length(body_plain)>40 order by sent_at limit 3", [$id]);
    $shown = 0;
    foreach ($primary as $p) {
        preg_match_all('/[\p{L}]{6,}/u', $p->body_plain, $m);
        if (isset($m[0][0]) && str_contains($html, $m[0][0])) { $shown++; }
    }
    $counts = DB::selectOne("select count(*) n, count(*) filter (where body_plain is null or body_plain='') empty from email_messages where related_request_id=?", [$id]);
    $ok = $response->getStatusCode() === 200 && str_contains($html, $code);
    $bad += $ok ? 0 : 1;
    echo sprintf("%-15s %-13s id=%-6d %d %4.1fс писем=%-3d пустых=%-2d текст виден %d/%d\n", $label, $code, $id, $response->getStatusCode(), microtime(true) - $t, $counts->n, $counts->empty, $shown, count($primary));
    Auth::guard('web')->forgetUser();
    $app->forgetInstance('session.store');
}
echo 'Карточек с ошибкой: ', $bad, PHP_EOL;
echo 'Задач в очереди: ', DB::table('jobs')->count(), PHP_EOL;
$r = DB::selectOne("select count(*) filter (where length(subject)=0) notheme, count(*) filter (where client_email='') noclient from requests");
echo 'Заявок без темы: ', $r->notheme, ', без клиента: ', $r->noclient, PHP_EOL;
$e = DB::selectOne("select count(*) filter (where (body_plain is null or body_plain='') and (body_html is null or body_html='')) nobody, count(*) n from email_messages where related_request_id is not null and not jsonb_exists(detected_artifacts,'cross_mailbox_copy_of')");
echo 'Писем без текста: ', $e->nobody, ' из ', $e->n, PHP_EOL;
