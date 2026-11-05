<?php
// Проверка на базе MyLift_retro: страницы открываются под разными ролями, без ошибок и без задач в очереди.
// Печатает только код ответа и наличие ключевых элементов, данные клиентов не выводит.
require '/var/www/mzcorp/vendor/autoload.php';
$app = require '/var/www/mzcorp/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Request as Req;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$c = config('database.default');
config(["database.connections.$c.database" => 'MyLift_retro', 'session.driver' => 'array', 'app.debug' => false]);
DB::purge($c);

$jobsBefore = DB::table('jobs')->count();
$quoted = Req::where('status', 'quoted')->orderByDesc('last_activity_at')->first();
$invoiced = Req::where('status', 'invoiced')->orderByDesc('last_activity_at')->first();
$closed = Req::where('status', 'closed_lost')->whereNotNull('onec_number')->orderByDesc('last_activity_at')->first();
$busy = Req::query()->whereIn('id', DB::table('email_messages')->select('related_request_id')->groupBy('related_request_id')->orderByRaw('count(*) desc')->limit(1))->first();
$manager = User::find($quoted->assigned_user_id);
$admin = User::find(1);

$pages = [
    ['админ, пул', $admin, '/dashboard/requests', []],
    ['админ, главная', $admin, '/dashboard', []],
    ['админ, КП отправлено', $admin, '/dashboard/requests/'.$quoted->id, ['Переписка', $quoted->internal_code]],
    ['админ, счёт', $admin, '/dashboard/requests/'.$invoiced->id, [$invoiced->internal_code]],
    ['админ, закрытая', $admin, '/dashboard/requests/'.$closed->id, [$closed->internal_code]],
    ['админ, самая длинная', $admin, '/dashboard/requests/'.$busy->id, [$busy->internal_code]],
    ['менеджер, пул', $manager, '/dashboard/requests', []],
    ['менеджер, своя заявка', $manager, '/dashboard/requests/'.$quoted->id, [$quoted->internal_code]],
    ['счета', $admin, '/invoices', []],
    ['аналитика', $admin, '/dashboard/analytics', []],
    ['клиенты', $admin, '/dashboard/clients', []],
];

foreach ($pages as [$label, $user, $url, $needles]) {
    Auth::guard('web')->setUser($user);
    $request = HttpRequest::create($url, 'GET');
    $t = microtime(true);
    try {
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $html = (string) $response->getContent();
        $found = array_map(fn ($n) => str_contains($html, $n) ? 'да' : 'НЕТ', $needles);
        echo sprintf("%-26s %s %5.1f с %6d КБ  %s\n", $label, $status, microtime(true) - $t, strlen($html) / 1024, implode(' ', $found));
        if ($status >= 400 && ($e = $response->exception ?? null)) {
            echo '   ', get_class($e), ': ', mb_substr($e->getMessage(), 0, 240), ' @ ', basename($e->getFile()), ':', $e->getLine(), PHP_EOL;
        }
    } catch (Throwable $e) {
        echo $label, ' ИСКЛЮЧЕНИЕ ', mb_substr($e->getMessage(), 0, 240), ' @ ', basename($e->getFile()), ':', $e->getLine(), PHP_EOL;
    }
    Auth::guard('web')->forgetUser();
    $app->forgetInstance('session.store');
}
echo 'Задач в очереди: было ', $jobsBefore, ', стало ', DB::table('jobs')->count(), PHP_EOL;
