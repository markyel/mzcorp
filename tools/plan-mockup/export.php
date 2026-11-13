<?php
use Illuminate\Support\Facades\DB;
$uid = (int) (getenv('PLAN_USER_ID') ?: 3); // 3 — Сергей Агрызков
$router = app(\App\Services\Mail\CitedOutboundQuoteRouter::class);
$reqs = \App\Models\Request::query()->where('assigned_user_id', $uid)
    ->whereNotIn('status', ['closed_won', 'closed_lost'])->whereNull('merged_into_id')
    ->orderByDesc('last_activity_at')->limit(300)->get();
// Платёжная дисциплина: по организации, иначе по адресу клиента.
$clientKey = fn ($r) => $r->organization_id ? 'org:'.$r->organization_id : 'email:'.mb_strtolower((string) $r->client_email);
$allReqs = \App\Models\Request::query()->whereIn('organization_id', $reqs->pluck('organization_id')->filter()->unique())
    ->orWhereIn(DB::raw('lower(client_email)'), $reqs->pluck('client_email')->filter()->map(fn ($e) => mb_strtolower($e))->unique())
    ->get(['id', 'organization_id', 'client_email', 'status']);
$inv = \App\Models\Invoice::query()->whereIn('request_id', $allReqs->pluck('id'))->whereNull('cancelled_at')->get();
$stats = [];
foreach ($allReqs as $r) {
    $k = $clientKey($r);
    $stats[$k] ??= ['requests' => 0, 'won' => 0, 'invoices' => 0, 'paid' => 0, 'pay_days' => [], 'paid_sum' => 0];
    $stats[$k]['requests']++;
    if ($r->status?->value === 'closed_won' || $r->status?->value === 'paid') $stats[$k]['won']++;
    foreach ($inv->where('request_id', $r->id) as $i) {
        $stats[$k]['invoices']++;
        if ($i->paid_at || $i->partially_paid_at) {
            $stats[$k]['paid']++;
            $stats[$k]['paid_sum'] += (float) ($i->paid_amount ?? $i->amount_snapshot ?? 0);
            if ($i->issued_at && $i->paid_at) $stats[$k]['pay_days'][] = $i->issued_at->diffInDays($i->paid_at);
        }
    }
}
$out = [];
foreach ($reqs as $r) {
    $quotes = \App\Models\OutboundQuote::query()->where('request_id', $r->id)->orderByDesc('id')->get();
    $lastQuote = $quotes->firstWhere('document_type', 'quote') ?? $quotes->first();
    $invoices = $inv->where('request_id', $r->id)->sortByDesc('id')->values();
    $lastIn = \App\Models\EmailMessage::query()->where('related_request_id', $r->id)->where('direction', 'inbound')->orderByDesc('sent_at')->first();
    // Автописьма (напоминания, «заявка получена») — отдельно: ответ менеджера — только ручное письмо.
    $autoIds = DB::table('client_notifications_sent')->where('request_id', $r->id)->whereNotNull('outgoing_email_message_id')->pluck('outgoing_email_message_id')->all();
    $lastAuto = DB::table('client_notifications_sent')->where('request_id', $r->id)->orderByDesc('sent_at')->first(['type', 'sent_at']);
    $lastOut = \App\Models\EmailMessage::query()->where('related_request_id', $r->id)->where('direction', 'outbound')->where('is_draft', false)
        ->whereNotIn('id', $autoIds ?: [0])->orderByDesc('sent_at')->first();
    $own = $lastIn ? trim(preg_replace('/\s+/u', ' ', $router->ownBodyText($lastIn))) : null;
    $k = $clientKey($r);
    $s = $stats[$k] ?? null;
    $out[] = [
        'code' => $r->internal_code, 'id' => $r->id, 'status' => $r->status?->value, 'subject' => mb_substr((string) $r->subject, 0, 120),
        'client' => $r->client_company ?: $r->client_name ?: $r->client_email, 'client_email' => $r->client_email,
        'attention_reason' => $r->attention_reason, 'attention_at' => $r->attention_required_at?->toIso8601String(), 'attention_level' => $r->attention_level,
        'last_activity_at' => $r->last_activity_at?->toIso8601String(), 'last_activity_type' => $r->last_activity_type,
        'created_at' => $r->created_at?->toIso8601String(), 'paused_until' => $r->paused_until?->toIso8601String(),
        'price_refresh_state' => $r->price_refresh_state, 'onec' => $r->onec_number,
        'items' => \App\Models\RequestItem::query()->where('request_id', $r->id)->where('is_active', true)->count(),
        'quote' => $lastQuote ? ['type' => $lastQuote->document_type, 'number' => $lastQuote->document_number, 'date' => (string) $lastQuote->document_date,
            'total' => (float) $lastQuote->total_amount, 'currency' => $lastQuote->currency, 'valid_until' => (string) $lastQuote->valid_until] : null,
        'quotes_count' => $quotes->where('document_type', 'quote')->count(),
        'invoice' => ($i = $invoices->first()) ? ['number' => $i->invoice_number, 'issued_at' => $i->issued_at?->toIso8601String(), 'status' => $i->status,
            'amount' => (float) $i->amount_snapshot, 'paid_at' => $i->paid_at?->toIso8601String(), 'partially' => $i->partially_paid_at?->toIso8601String(),
            'expires_at' => $i->expires_at?->toIso8601String()] : null,
        'last_in' => $lastIn ? ['at' => $lastIn->sent_at?->toIso8601String(), 'subject' => mb_substr((string) $lastIn->subject, 0, 100), 'text' => mb_substr((string) $own, 0, 400)] : null,
        'last_out' => $lastOut ? ['at' => $lastOut->sent_at?->toIso8601String(), 'subject' => mb_substr((string) $lastOut->subject, 0, 100)] : null,
        'last_auto' => $lastAuto ? ['type' => $lastAuto->type, 'at' => \Illuminate\Support\Carbon::parse($lastAuto->sent_at)->toIso8601String()] : null,
        'ai' => \App\Models\AiDecision::query()->where('request_id', $r->id)->where('status', 'pending')->pluck('detector_type')->all(),
        'client_stats' => $s ? ['requests' => $s['requests'], 'won' => $s['won'], 'invoices' => $s['invoices'], 'paid' => $s['paid'],
            // Медиана: среднее портят старые счета с неточными датами (у клиента «обычно за 171 дн.»).
            'avg_pay_days' => $s['pay_days'] ? (function ($v) { sort($v); $n = count($v); return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; })($s['pay_days']) : null, 'paid_sum' => round($s['paid_sum'])] : null,
    ];
}
file_put_contents('/tmp/plan-sergey.json', json_encode(['generated_at' => now()->toIso8601String(), 'manager' => \App\Models\User::find($uid)->name, 'requests' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo count($out), " requests\n";
echo json_encode(collect($out)->countBy('status')), "\n", json_encode(collect($out)->countBy('attention_reason')), "\n";
