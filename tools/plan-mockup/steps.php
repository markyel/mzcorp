<?php
// Макет «Мой план»: шаги по порядку + формулировка ИИ (только чтение, в базу ничего не пишет; вызывает OpenAI).
// Вход: /tmp/plan-sergey.json (export.php). Выход: /tmp/plan-steps.json.
use Illuminate\Support\Facades\DB;

$d = json_decode(file_get_contents('/tmp/plan-sergey.json'), true);
$now = \Illuminate\Support\Carbon::parse($d['generated_at']);
$h = fn ($s) => $s ? \Illuminate\Support\Carbon::parse($s)->diffInMinutes($now, false) / 60 : null; // >0 — прошло
$router = app(\App\Services\Mail\CitedOutboundQuoteRouter::class);

$reWant = '/(?:выстав|пришл|направ|прош|нуж|жд[её]м|давайте)\S*\s+(?:\S+\s+){0,3}сч[её]т|сч[её]т\S*\s+на\s+оплату/iu';
$reFix = '/исправ\S*\s+(?:\S+\s+){0,2}сч[её]т|сч[её]т\S*\s+(?:\S+\s+){0,3}исправ/iu';

$steps = [];
$stale = [];
foreach ($d['requests'] as $r) {
    $txt = $r['last_in']['text'] ?? '';
    $attH = $h($r['attention_at']);
    $amount = $r['invoice']['amount'] ?? $r['quote']['total'] ?? 0;
    $answered = ($r['last_in']['at'] ?? null) && ($r['last_out']['at'] ?? null) && $r['last_out']['at'] > $r['last_in']['at'];
    $kind = null;
    $base = 0;
    if (in_array('inbound_possible_new_request', $r['ai'], true)) {
        [$kind, $base] = ['possible_new_request', 40];
    } elseif (in_array($r['attention_reason'], ['client_replied', 'post_sale'], true) && $answered) {
        $stale[] = ['code' => $r['code'], 'id' => $r['id'], 'client' => $r['client'], 'in' => $r['last_in']['at'], 'out' => $r['last_out']['at'], 'out_subject' => $r['last_out']['subject']];
        continue;
    } elseif ($r['attention_reason'] === 'client_replied') {
        if (preg_match($reWant, $txt) && ($r['invoice']['issued_at'] ?? '') > ($r['last_in']['at'] ?? '')) {
            continue; // счёт уже выставлен после просьбы
        }
        [$kind, $base] = preg_match($reFix, $txt) ? ['fix_invoice', 90] : (preg_match($reWant, $txt) ? ['issue_invoice', 100] : ['reply', 70]);
    } elseif ($r['attention_reason'] === 'post_sale') {
        [$kind, $base] = ['post_sale', 65];
    } elseif (in_array($r['attention_reason'], ['fresh_assignment', 'sla_breach'], true)
        && $r['quote'] && $r['last_out'] && ($r['last_in']['at'] ?? '') < $r['last_out']['at']) {
        // КП уже ушло после письма клиента — отметка устарела.
        $stale[] = ['code' => $r['code'], 'id' => $r['id'], 'client' => $r['client'], 'in' => $r['last_in']['at'] ?? $r['created_at'], 'out' => $r['last_out']['at'], 'out_subject' => $r['last_out']['subject']];
        continue;
    } elseif ($r['attention_reason'] === 'fresh_assignment') {
        [$kind, $base] = ['new_request', 80];
    } elseif ($r['attention_reason'] === 'sla_breach') {
        [$kind, $base] = ['prepare_quote', 75];
    } elseif (in_array($r['attention_reason'], ['supplier_replied', 'prices_actualized'], true)) {
        [$kind, $base] = ['update_quote', 60];
    } elseif ($r['status'] === 'invoiced' && $r['invoice'] && ! $r['invoice']['paid_at']) {
        $days = $h($r['invoice']['issued_at']) / 24;
        if ($days > max(3, ($r['client_stats']['avg_pay_days'] ?? 3) + 2)) {
            [$kind, $base] = ['payment_reminder', 55];
        }
    } elseif ($r['status'] === 'quoted' && $attH > 0) {
        $reminded = ($r['last_auto']['type'] ?? null) === 'quote_followup_reminder' && $h($r['last_auto']['at']) < 72;
        [$kind, $base] = [$reminded ? 'call_quote' : 'follow_quote', $reminded ? 15 : 30];
    } elseif ($r['status'] === 'awaiting_client_clarification' && $attH > 0) {
        [$kind, $base] = ['clarification_reminder', 20];
    }
    if ($kind === null) {
        continue;
    }
    $cs = $r['client_stats'];
    $pay = $cs && $cs['invoices'] >= 3 ? $cs['paid'] / $cs['invoices'] : 0.3;
    $score = $base + min(max($attH ?? 0, 0), 72) * 0.6 + log10($amount + 1) * 6 + $pay * 15;
    $steps[] = ['r' => $r, 'kind' => $kind, 'score' => $score, 'overdue_h' => $attH];
}
usort($steps, fn ($a, $b) => $b['score'] <=> $a['score']);

$kinds = [
    'issue_invoice' => 'клиент просит выставить счёт', 'fix_invoice' => 'клиент просит исправить счёт', 'reply' => 'клиент написал — нужен ответ',
    'post_sale' => 'письмо после счёта (оплата, отгрузка, документы)', 'new_request' => 'новая заявка, КП ещё нет', 'prepare_quote' => 'заявка без КП, срок подготовки вышел',
    'update_quote' => 'ответили поставщики / цены обновлены — пересобрать КП', 'payment_reminder' => 'счёт не оплачен дольше обычного для клиента',
    'follow_quote' => 'КП без ответа клиента', 'call_quote' => 'КП без ответа, автонапоминание уже ушло', 'clarification_reminder' => 'ждём от клиента уточнения',
    'possible_new_request' => 'ИИ считает последнее письмо новой заявкой внутри старой переписки',
];
// Глагол шага — по правилам (ИИ путал стороны: «Напомнить клиенту» там, где КП должны мы).
// null — шаг по письму клиента: глагол выбирает ИИ из короткого списка.
$verbs = [
    'issue_invoice' => 'Выставить счёт', 'fix_invoice' => 'Исправить счёт', 'new_request' => 'Подготовить КП', 'prepare_quote' => 'Подготовить КП',
    'update_quote' => 'Пересобрать КП', 'payment_reminder' => 'Напомнить об оплате', 'follow_quote' => 'Напомнить о КП', 'call_quote' => 'Позвонить по КП',
    'clarification_reminder' => 'Напомнить про уточнение', 'reply' => null, 'post_sale' => null, 'possible_new_request' => 'Решить: новая заявка или дополнение',
];
$system = <<<'TXT'
Ты — помощник менеджера по продажам запчастей для лифтов («Мой Лифт»). По фактам заявки допиши шаг менеджера.
Ответ — JSON {"verb": "...", "object": "...", "comment": "...", "needs_action": true|false}.

verb — если в фактах дан «глагол шага», верни его без изменений. Если не дан (шаг по письму клиента), выбери ОДИН по смыслу его последнего письма:
  «Ответить» — вопрос или просьба, на которые нужно ответить;
  «Выставить счёт» — клиент просит счёт ИЛИ подтверждает заказ/покупку;
  «Исправить счёт» — просит поправить выставленный счёт;
  «Подготовить отгрузку» — сообщает о приезде за товаром, просит отгрузить, прислал подписанные документы для отгрузки;
  «Прочитать и снять отметку» — нет вопроса и просьбы: благодарность, «подниму вопрос», «получили» (тогда needs_action false).
object — предмет шага, 2–6 слов: о чём ответить или на что КП/счёт (позиция, вопрос). Например: «длина лыжи отводки V1 YDK», «плата UPS MLPOWER 3, 1 шт», «счёт 11734».
  Не повторяй глагол, без номера заявки.
comment — одно-два законченных предложения до 150 знаков: конкретика, которой нет в verb и object — что именно спросил или попросил клиент, срок, сумма, особенность клиента.
  Запрещены фразы без смысла: «важно», «необходимо», «нужно уточнить», «ждём ответа», «убедитесь», «стоит», «для дальнейшей работы». Без английских слов. Суммы как «12 193 ₽».
  Хорошо: «Клиент прислал фото: лыжа должна быть длиннее, просит цену и срок на правую отводку.»
  Хорошо: «КП на 1,8 млн ₽ от 05.11; клиент обычно платит в день счёта — звонок может закрыть сделку.»
Только факты из данных, ничего не выдумывай.
TXT;

$chat = app(\App\Services\AI\OpenAIChatService::class);
$out = [];
foreach ($steps as $i => $s) {
    $r = $s['r'];
    $items = \App\Models\RequestItem::query()->where('request_id', $r['id'])->where('is_active', true)->orderBy('position')->limit(8)
        ->get(['parsed_name', 'parsed_qty', 'parsed_unit'])->map(fn ($it) => trim($it->parsed_name.' — '.rtrim(rtrim((string) $it->parsed_qty, '0'), '.').' '.$it->parsed_unit))->all();
    $ourText = null;
    if ($r['last_out']) {
        $m = \App\Models\EmailMessage::query()->where('related_request_id', $r['id'])->where('direction', 'outbound')->where('is_draft', false)
            ->where('sent_at', $r['last_out']['at'] ? \Illuminate\Support\Carbon::parse($r['last_out']['at']) : null)->first();
        $ourText = $m ? mb_substr(trim(preg_replace('/\s+/u', ' ', $router->ownBodyText($m))), 0, 500) : null;
    }
    $clientIn = null;
    if ($r['last_in']) {
        $m = \App\Models\EmailMessage::query()->where('related_request_id', $r['id'])->where('direction', 'inbound')
            ->where('sent_at', \Illuminate\Support\Carbon::parse($r['last_in']['at']))->first();
        $clientIn = $m ? mb_substr(trim(preg_replace('/\s+/u', ' ', $router->ownBodyText($m))), 0, 1500) : $r['last_in']['text'];
    }
    $cs = $r['client_stats'];
    $statusRu = ['quoted' => 'КП отправлено', 'invoiced' => 'счёт выставлен', 'in_progress' => 'в работе', 'assigned' => 'назначена',
        'awaiting_client_clarification' => 'ждём уточнения от клиента', 'under_review' => 'на согласовании', 'paused' => 'пауза'];
    $invRu = ['pending' => 'ждёт оплаты', 'expired' => 'срок счёта истёк', 'paid' => 'оплачен', 'partially_paid' => 'оплачен частично'];
    $r['status'] = $statusRu[$r['status']] ?? $r['status'];
    if ($r['invoice']) {
        $r['invoice']['status'] = $invRu[$r['invoice']['status']] ?? $r['invoice']['status'];
    }
    $facts = [
        'шаг' => $kinds[$s['kind']],
        'глагол шага' => $verbs[$s['kind']] ?? 'не дан — выбери по письму клиента',
        'заявка' => $r['code'].' · '.$r['subject'],
        'клиент' => $r['client'],
        'статус' => $r['status'],
        'КП' => $r['quote'] ? ($r['quote']['number'].' от '.substr($r['quote']['date'], 0, 10).' на '.number_format($r['quote']['total'], 2, ',', ' ').' '.$r['quote']['currency']) : 'нет',
        'счёт' => $r['invoice'] ? ($r['invoice']['number'].' от '.substr($r['invoice']['issued_at'], 0, 10).' на '.number_format($r['invoice']['amount'], 2, ',', ' ').' ₽, статус '.$r['invoice']['status']) : 'нет',
        'оплаты клиента' => $cs && $cs['invoices'] ? $cs['paid'].' из '.$cs['invoices'].' счетов оплачено' : 'счетов не было',
        'позиции' => $items,
        'последнее письмо клиента' => $r['last_in'] ? substr($r['last_in']['at'], 0, 16).': '.$clientIn : 'нет',
        'наше последнее письмо' => $r['last_out'] ? substr($r['last_out']['at'], 0, 16).': '.($ourText ?? $r['last_out']['subject']) : 'нет',
        'автонапоминание' => $r['last_auto'] ? $r['last_auto']['type'].' '.substr($r['last_auto']['at'], 0, 16) : 'нет',
    ];
    try {
        $res = $chat->chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
            'gpt-4o-mini', ['response_format' => ['type' => 'json_object'], 'temperature' => 0.2]);
        $ai = json_decode($res['content'], true) ?: [];
        // Глагол по правилам главнее ответа модели.
        $verb = $verbs[$s['kind']] ?? (in_array($ai['verb'] ?? '', ['Ответить', 'Выставить счёт', 'Исправить счёт', 'Подготовить отгрузку', 'Прочитать и снять отметку'], true) ? $ai['verb'] : 'Ответить');
        $ai['needs_action'] = $verb !== 'Прочитать и снять отметку';
        $ai['action'] = $verb.(trim((string) ($ai['object'] ?? '')) !== '' && $ai['needs_action'] ? ': '.trim($ai['object']) : '');
    } catch (\Throwable $e) {
        $ai = ['error' => $e->getMessage()];
    }
    $out[] = ['code' => $r['code'], 'id' => $r['id'], 'client' => $r['client'], 'kind' => $s['kind'], 'score' => round($s['score'], 1),
        'overdue_h' => $s['overdue_h'] !== null ? round($s['overdue_h'], 1) : null, 'amount' => $r['invoice']['amount'] ?? $r['quote']['total'] ?? null,
        'amount_is_invoice' => (bool) $r['invoice'] && $r['status'] === 'счёт выставлен', 'client_stats' => $cs, 'ai' => $ai];
    echo ($i + 1).' '.$r['code'].' '.($ai['action'] ?? ($ai['error'] ?? '?'))."\n";
}
file_put_contents('/tmp/plan-steps.json', json_encode(['generated_at' => $d['generated_at'], 'manager' => $d['manager'], 'total' => count($d['requests']),
    'steps' => $out, 'stale' => $stale], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo count($out)." steps, ".count($stale)." stale\n";
