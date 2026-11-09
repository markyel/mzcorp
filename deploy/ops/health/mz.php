<?php
// Read-only health snapshot of mzCorp for the last N minutes.
require '/var/www/mzcorp/vendor/autoload.php';
$app = require '/var/www/mzcorp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$since = now()->subMinutes((int) ($argv[1] ?? 30));
$c = fn ($q) => (int) $q->count();
echo "== mzCorp since ", $since->format('H:i'), PHP_EOL;
echo "inbound NEW (sent in window): ", $c(DB::table("email_messages")->where("direction","inbound")->where("created_at",">=",$since)->where("sent_at",">=",$since->copy()->subHours(4))), " · old history loaded: ", $c(DB::table("email_messages")->where("direction","inbound")->where("created_at",">=",$since)->where("sent_at","<",$since->copy()->subHours(4))),
     " · outbound: ", $c(DB::table('email_messages')->where('direction','outbound')->where('created_at','>=',$since)),
     " · last inbound at: ", DB::table('email_messages')->where('direction','inbound')->max('created_at'), PHP_EOL;
echo "new requests: ", $c(DB::table('requests')->where('created_at','>=',$since)),
     " · assignments: ", json_encode(DB::table('request_assignments')->where('created_at','>=',$since)->selectRaw('reason, count(*) c')->groupBy('reason')->pluck('c','reason')), PHP_EOL;
echo "state changes: ", json_encode(DB::table('request_state_changes')->where('created_at','>=',$since)->selectRaw("event||'→'||to_status k, count(*) c")->groupBy('k')->orderByDesc('c')->limit(15)->pluck('c','k'), JSON_UNESCAPED_UNICODE), PHP_EOL;
echo "mail decisions: ", json_encode(DB::table('mail_decisions')->where('created_at','>=',$since)->selectRaw("outcome, count(*) c")->groupBy('outcome')->pluck('c','outcome')), PHP_EOL;
echo "pending w/o manager: ", $c(DB::table('requests')->where('status','pending')->whereNull('assigned_user_id')), PHP_EOL;
echo "queue: ", json_encode(DB::table('jobs')->selectRaw('queue, count(*) c, min(created_at) oldest')->groupBy('queue')->get()), PHP_EOL;
echo "failed jobs: ", json_encode(DB::table('failed_jobs')->where('failed_at','>=',$since)->get(['queue','payload','exception'])->map(fn ($j) => [json_decode($j->payload)->displayName ?? '?', mb_substr(strtok($j->exception, "\n"), 0, 160)])->groupBy(0)->map(fn ($g) => [count($g), $g[0][1]]), JSON_UNESCAPED_UNICODE), PHP_EOL;
