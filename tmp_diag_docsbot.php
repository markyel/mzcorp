<?php

use App\Models\User;
use App\Models\Request;
use App\Models\RequestAssignment;
use Illuminate\Support\Facades\DB;

$bot = User::find(19);
echo "=== docs-bot ===\n";
echo "id=".$bot->id." email=".$bot->email." role=".$bot->roles->pluck("name")->implode(",")."\n";
echo "archived_at=".($bot->archived_at ?? "NULL")."\n";
echo "unavailable_until=".($bot->unavailable_until ?? "NULL")."\n";

echo "\n=== Все assignment'ы где user=docs-bot ===\n";
foreach (RequestAssignment::where("user_id", 19)->orderBy("id")->get() as $a) {
    $r = Request::find($a->request_id);
    echo "   #".$a->id." req=".$a->request_id." (".$r?->internal_code.") reason=".$a->reason." created=".$a->created_at."\n";
}

echo "\n=== Все Request куда был docs-bot хотя бы раз ===\n";
$reqIds = RequestAssignment::where("user_id", 19)->pluck("request_id")->unique();
foreach ($reqIds as $rid) {
    $r = Request::find($rid);
    echo "  req=".$rid." (".$r?->internal_code.") now assigned_user_id=".($r?->assigned_user_id ?? "NULL")." (".($r?->assignedUser?->name ?? "none").")\n";
    foreach (RequestAssignment::where("request_id", $rid)->orderBy("id")->get() as $a) {
        echo "      #".$a->id." user=".$a->user?->name." reason=".$a->reason." at=".$a->created_at."\n";
    }
}

echo "\n=== email_messages.detected_artifacts с user_id=19 в inbox_deliveries ===\n";
$rows = DB::select("
    SELECT id, mailbox_id, message_id, subject, created_at,
           detected_artifacts->'inbox_deliveries' AS deliveries
    FROM email_messages
    WHERE detected_artifacts::text LIKE '%\"user_id\":19%'
      OR detected_artifacts::text LIKE '%\"user_id\": 19%'
    ORDER BY id
");
echo "found: ".count($rows)."\n";
foreach ($rows as $r) {
    echo "  msg#".$r->id." mb=".$r->mailbox_id." created=".$r->created_at." subj=".mb_substr($r->subject ?: "", 0, 60)."\n";
    echo "    deliveries=".($r->deliveries ?? "null")."\n";
}
