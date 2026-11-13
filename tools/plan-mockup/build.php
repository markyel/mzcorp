<?php
// Макет «Мой план» v2 на дизайн-системе (design/colors_and_type.css + компоненты UI-кита crm): шаги по порядку.
$dir = __DIR__;
$root = dirname(__DIR__, 2).'/design';
$data = json_encode(json_decode(file_get_contents($dir.'/plan-steps.json'), true), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$css = preg_replace('/@import\s+url\([^)]*\)\s*;/', '', file_get_contents($root.'/colors_and_type.css'));
$logo = file_get_contents($root.'/assets/logos/mylift-wordmark.svg');

$html = <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мой план</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<style>
__CSS__
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg-app);font-family:var(--font-sans);color:var(--fg-1);font-size:var(--fs-base)}
/* оболочка — как в UI-ките crm */
.app{display:grid;grid-template-columns:var(--rail-w) 1fr;grid-template-rows:var(--topbar-h) 1fr;min-height:100vh}
.top{grid-column:1/-1;display:flex;align-items:center;gap:16px;padding:0 16px;background:var(--bg-surface);border-bottom:1px solid var(--border)}
.top .brand svg{height:24px;width:auto;display:block}
.top .spacer{flex:1}
.top .av{width:28px;height:28px;border-radius:999px;background:var(--neutral-300);color:var(--fg-1);font:600 11px/28px var(--font-sans);text-align:center}
.rail{background:var(--bg-sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;align-items:center;padding:8px 0;gap:2px}
.rail .item{width:40px;height:40px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;color:var(--fg-3);position:relative}
.rail .item.active{color:var(--accent);background:var(--bg-surface)}
.rail .item.active::before{content:"";position:absolute;left:-8px;top:8px;bottom:8px;width:2px;background:var(--accent);border-radius:0 2px 2px 0}
.rail svg{width:20px;height:20px;stroke-width:1.5}
.main{padding:20px 24px 40px;max-width:1120px;width:100%}
.mock{font:400 12px/1.4 var(--font-sans);color:var(--fg-3);border:1px dashed var(--border-strong);border-radius:var(--r-md);padding:6px 10px;margin-bottom:14px}
.pageh{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:16px;flex-wrap:wrap}
.pageh .crumbs{font:500 11.5px/1 var(--font-sans);color:var(--fg-3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.pageh h1{margin:0;font:600 22px/1.2 var(--font-sans);letter-spacing:-0.01em}
.pageh .meta{font:400 12.5px/1 var(--font-sans);color:var(--fg-3);margin-top:6px}
.progress{min-width:260px}
.progress .lbl{font:500 12px/1 var(--font-sans);color:var(--fg-2);display:flex;justify-content:space-between;margin-bottom:6px}
.progress .bar{height:6px;background:var(--neutral-100);border-radius:999px;overflow:hidden}
.progress .bar i{display:block;height:100%;background:var(--emerald-600);transition:width var(--dur-panel) var(--ease)}
/* компоненты UI-кита */
.card{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--r-md);margin-bottom:16px}
.card .ch{display:flex;align-items:center;gap:10px;padding:12px 18px;border-bottom:1px solid var(--border-subtle)}
.card .ch h3{margin:0;font:600 13.5px/1.3 var(--font-sans)}
.card .ch .badge{font:600 10.5px/1.3 var(--font-sans);background:var(--neutral-100);color:var(--fg-2);padding:2px 7px;border-radius:999px}
.card .ch .spacer{flex:1}
.card .ch .small{font:500 11.5px/1 var(--font-sans);color:var(--fg-3)}
.chip{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font:500 11.5px/1.4 var(--font-sans);white-space:nowrap}
.chip .dot{width:5px;height:5px;border-radius:999px;background:currentColor}
.chip.neutral{background:var(--neutral-100);color:var(--neutral-700)}
.chip.over{background:var(--red-50);color:var(--red-700)}
.chip.attn{background:var(--amber-50);color:var(--amber-700)}
.chip.ok{background:var(--emerald-50);color:var(--emerald-700)}
.chip.info{background:var(--sky-50);color:var(--sky-700)}
.chip.paused{background:var(--violet-50);color:var(--violet-700)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:30px;padding:0 12px;border:1px solid var(--border-strong);border-radius:var(--r-md);background:var(--bg-surface);font:500 12.5px/1 var(--font-sans);color:var(--fg-1);cursor:pointer;white-space:nowrap;text-decoration:none}
.btn:hover{background:var(--bg-hover)}
.btn.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn.primary:hover{background:var(--accent-600)}
.btn.ghost{border-color:transparent;background:transparent;color:var(--fg-2)}
.btn svg{width:16px;height:16px;stroke-width:1.5}
.code{font:500 12.5px/1 var(--font-mono);color:var(--sky-700);text-decoration:none}
.code:hover{text-decoration:underline}
/* текущий шаг */
.now .body{padding:16px 18px 18px}
.now .act{font:600 17px/1.35 var(--font-sans);margin:0 0 6px}
.now .who{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;font-size:13px;color:var(--fg-2);margin-bottom:10px}
.now .comment{font:400 14px/1.5 var(--font-sans);color:var(--fg-1);margin:0 0 12px;max-width:760px}
.now .facts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.now .actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.menu{position:relative;display:inline-block}
.menu .pop{display:none;position:absolute;left:0;top:34px;z-index:5;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--r-md);box-shadow:var(--shadow-md);min-width:240px;padding:4px}
.menu.open .pop{display:block}
.pop .h{font:600 10.5px/1 var(--font-sans);color:var(--fg-3);text-transform:uppercase;letter-spacing:.04em;padding:8px 10px 6px}
.pop button{display:block;width:100%;text-align:left;border:0;background:none;font:400 13px/1.3 var(--font-sans);padding:7px 10px;border-radius:4px;cursor:pointer;color:var(--fg-1)}
.pop button:hover{background:var(--bg-hover)}
/* очередь */
.q{width:100%;border-collapse:collapse}
.q tr{height:var(--row-h);border-top:1px solid var(--border-subtle);cursor:pointer}
.q tr:hover{background:var(--bg-hover)}
.q td{padding:7px 12px;vertical-align:top;font-size:13px}
.q td.n{width:36px;color:var(--fg-4);font:500 12px/1.6 var(--font-mono);text-align:right}
.q td.a .t{font-weight:500}
.q td.a .c{color:var(--fg-3);font-size:12.5px;margin-top:2px;line-height:1.4}
.q td.k{white-space:nowrap;width:1%}
.q td.m{white-space:nowrap;width:1%;text-align:right}
.q tr.dim td.a .t{color:var(--fg-3);font-weight:400}
.group-label{font:600 10.5px/1 var(--font-sans);color:var(--fg-3);text-transform:uppercase;letter-spacing:.04em;padding:12px 18px 8px;border-top:1px solid var(--border-subtle)}
.empty{padding:28px 18px;text-align:center;color:var(--fg-3)}
@media (max-width:720px){.app{grid-template-columns:1fr}.rail{display:none}.main{padding:16px}.q td.k,.q td.m{display:none}}
</style>
</head>
<body>
<div class="app">
  <header class="top"><div class="brand">__LOGO__</div><div class="spacer"></div><div class="av" id="av"></div></header>
  <nav class="rail">
    <div class="item active" title="Мой план"><i data-lucide="list-checks"></i></div>
    <div class="item" title="Заявки"><i data-lucide="inbox"></i></div>
    <div class="item" title="Почта"><i data-lucide="mail"></i></div>
    <div class="item" title="Счета"><i data-lucide="file-text"></i></div>
    <div class="item" title="Каталог"><i data-lucide="search"></i></div>
  </nav>
  <main class="main">
    <div class="mock">Макет на срезе данных (только чтение): кнопки показывают поведение, в систему ничего не пишут. Тексты шагов написал ИИ по фактам заявки и переписке.</div>
    <div class="pageh">
      <div><div class="crumbs">Дашборд</div><h1>Мой план</h1><div class="meta" id="meta"></div></div>
      <div class="progress"><div class="lbl"><span>Пройдено</span><span id="plbl"></span></div><div class="bar"><i id="pbar" style="width:0"></i></div></div>
    </div>
    <div id="now"></div>
    <div id="queue"></div>
    <div id="side"></div>
  </main>
</div>
<script>
const DATA = __DATA__;
const NOW = new Date(DATA.generated_at);
const URL_BASE = 'https://mzcorp.ru/dashboard/requests/';
const rub = v => Math.round(v).toLocaleString('ru-RU').replace(/\u00a0|,/g, ' ') + ' ₽';
const dt = s => { const d = new Date(s); return d.toLocaleDateString('ru-RU', {day: '2-digit', month: '2-digit'}) + ' ' + d.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'}); };
const ago = h => h < 1 ? Math.max(1, Math.round(h * 60)) + ' мин' : h < 23.5 ? Math.round(h) + ' ч' : Math.max(1, Math.round(h / 24)) + ' дн.';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
const KIND = {issue_invoice: 'счёт', fix_invoice: 'счёт', reply: 'ответ клиенту', post_sale: 'после счёта', new_request: 'новая заявка', prepare_quote: 'КП',
  update_quote: 'цены поставщиков', payment_reminder: 'оплата', follow_quote: 'КП без ответа', call_quote: 'КП без ответа', clarification_reminder: 'уточнение', possible_new_request: 'новая заявка?'};

function pay(cs) {
  if (!cs || !cs.invoices) return {t: 'счетов ещё не было', c: 'neutral'};
  if (cs.invoices < 3) return {t: 'оплачено ' + cs.paid + ' из ' + cs.invoices + ' счетов', c: 'neutral'};
  const p = Math.round(cs.paid / cs.invoices * 100), n = cs.avg_pay_days != null ? Math.round(cs.avg_pay_days) : null;
  const when = n === null ? '' : n === 0 ? ', в день счёта' : n === 1 ? ', за 1 день' : ', за ' + n + ' дн.';
  return {t: 'оплачивает ' + p + '% счетов' + when, c: p >= 60 ? 'ok' : p < 35 ? 'over' : 'attn'};
}
function chips(s) {
  const out = [];
  if (s.amount) out.push('<span class="chip neutral">' + rub(s.amount) + ' · ' + (s.amount_is_invoice ? 'счёт' : 'КП') + '</span>');
  if (s.overdue_h > 0) out.push('<span class="chip over"><span class="dot"></span>просрочено ' + ago(s.overdue_h) + '</span>');
  const p = pay(s.client_stats);
  out.push('<span class="chip ' + p.c + '">' + p.t + '</span>');
  return out.join(' ');
}

// Чистка текстов модели: «RUB» → ₽, повтор «КП: КП …», фразы-пустышки (если в комментарии есть что-то ещё).
const FILLER = /(напомина(ем|ть)\s+о\s+(предложении|кп)|жд[её]м\s+(его\s+)?ответ|ожидаем\s+его\s+решени|нужно\s+(учесть|уточнить|прояснить)|необходимо|для\s+дальнейшей\s+работы|как\s+можно\s+скорее|стоит\s+уточнить)/i;
function cleanText(t) {
  t = String(t || '').replace(/(\d)\s*RUB\b/g, '$1 ₽');
  const parts = t.split(/(?<=[.!])\s+/).filter(Boolean);
  const kept = parts.filter(p => !FILLER.test(p));
  return (kept.length ? kept : parts).join(' ');
}
function cleanAction(a) {
  return String(a || '').replace(/(\d)\s*RUB\b/g, '$1 ₽')
    .replace(/^(.*КП):\s*(?:КП|коммерческое предложение|предложение)\s+(?:на\s+)?/i, '$1: ')
    .replace(/^(.*сч[её]т):\s*сч[её]т\s+/i, '$1 ');
}
DATA.steps.forEach(s => { if (s.ai) { s.ai.action = cleanAction(s.ai.action); s.ai.comment = cleanText(s.ai.comment); } });

// Порядок: сначала шаги с делом, затем «прочитать и снять отметку».
const steps = DATA.steps.filter(s => s.ai && s.ai.action).sort((a, b) => (b.ai.needs_action !== false) - (a.ai.needs_action !== false) || b.score - a.score);
const state = {done: new Set(), postponed: {}, wrong: {}, current: null};
const open = () => steps.filter(s => !state.done.has(s.code) && !state.wrong[s.code]);
const ordered = () => { const o = open(); return o.filter(s => !state.postponed[s.code]).concat(o.filter(s => state.postponed[s.code])); };

function menu(id, label, icon, head, items) {
  return '<div class="menu"><button class="btn" onclick="toggleMenu(this)"><i data-lucide="' + icon + '"></i>' + label + '</button><div class="pop"><div class="h">' + head + '</div>'
    + items.map(([fn, t]) => '<button onclick="' + fn + '">' + t + '</button>').join('') + '</div></div>';
}
function toggleMenu(b) { document.querySelectorAll('.menu.open').forEach(m => m !== b.parentNode && m.classList.remove('open')); b.parentNode.classList.toggle('open'); }
document.addEventListener('click', e => { if (!e.target.closest('.menu')) document.querySelectorAll('.menu.open').forEach(m => m.classList.remove('open')); });

function done(code) { state.done.add(code); state.current = null; render(); }
function postpone(code, v) { state.postponed[code] = v; state.current = null; render(); }
function wrong(code, v) { state.wrong[code] = v; state.current = null; render(); }
function pick(code) { state.current = code; render(); window.scrollTo({top: 0, behavior: 'smooth'}); }

function render() {
  const list = ordered();
  const cur = list.find(s => s.code === state.current) || list[0];
  const total = steps.length, passed = state.done.size + Object.keys(state.wrong).length;
  document.getElementById('plbl').textContent = passed + ' из ' + total;
  document.getElementById('pbar').style.width = (total ? passed / total * 100 : 0) + '%';

  document.getElementById('now').innerHTML = !cur ? '<div class="card"><div class="empty">Все шаги пройдены. Хорошая работа.</div></div>'
    : '<div class="card now"><div class="ch"><h3>Шаг ' + (list.indexOf(cur) + 1) + ' из ' + list.length + '</h3><span class="badge">' + KIND[cur.kind] + '</span>'
      + (state.postponed[cur.code] ? '<span class="chip paused">отложено ' + state.postponed[cur.code] + '</span>' : '')
      + (cur.ai.needs_action === false ? '<span class="chip neutral">делать нечего</span>' : '') + '<span class="spacer"></span><span class="small">приоритет ' + Math.round(cur.score) + '</span></div>'
      + '<div class="body"><p class="act">' + esc(cur.ai.action) + '</p>'
      + '<div class="who"><a class="code" href="' + URL_BASE + cur.id + '" target="_blank">' + esc(cur.code) + '</a><span>' + esc(cur.client) + '</span></div>'
      + '<p class="comment">' + esc(cur.ai.comment) + '</p>'
      + '<div class="facts">' + chips(cur) + '</div>'
      + '<div class="actions">'
      + '<button class="btn primary" onclick="done(\'' + cur.code + '\')"><i data-lucide="check"></i>Готово — следующий шаг</button>'
      + '<a class="btn" href="' + URL_BASE + cur.id + '" target="_blank"><i data-lucide="external-link"></i>Открыть заявку</a>'
      + menu(cur.code, 'Отложить', 'clock', 'Понизить приоритет', [["postpone('" + cur.code + "','на 1 час')", 'На 1 час'], ["postpone('" + cur.code + "','до завтра')", 'До завтра 9:00'], ["postpone('" + cur.code + "','на 3 дня')", 'На 3 дня'], ["postpone('" + cur.code + "','в конец')", 'В конец списка']])
      + menu(cur.code, 'Неверно', 'circle-alert', 'Что не так — уйдёт РОПу', [["wrong('" + cur.code + "','статус заявки неверный')", 'Статус заявки неверный'], ["wrong('" + cur.code + "','данные неверные')", 'Сумма, клиент или письмо неверные'], ["wrong('" + cur.code + "','шаг не нужен')", 'Этот шаг не нужен']])
      + '</div></div></div>';

  const rest = list.filter(s => s !== cur);
  const row = (s, i) => '<tr class="' + (s.ai.needs_action === false ? 'dim' : '') + '" onclick="pick(\'' + s.code + '\')"><td class="n">' + (i + 2) + '</td>'
    + '<td class="a"><div class="t">' + esc(s.ai.action) + '</div><div class="c">' + esc(s.ai.comment) + '</div></td>'
    + '<td class="k"><a class="code" href="' + URL_BASE + s.id + '" target="_blank" onclick="event.stopPropagation()">' + esc(s.code) + '</a></td>'
    + '<td class="m">' + (state.postponed[s.code] ? '<span class="chip paused">отложено</span> ' : '') + (s.overdue_h > 0 ? '<span class="chip over">' + ago(s.overdue_h) + '</span>' : '')
    + (s.amount ? ' <span class="chip neutral">' + rub(s.amount) + '</span>' : '') + '</td></tr>';
  const act = rest.filter(s => s.ai.needs_action !== false), idle = rest.filter(s => s.ai.needs_action === false);
  document.getElementById('queue').innerHTML = !rest.length ? '' : '<div class="card"><div class="ch"><h3>Дальше</h3><span class="badge">' + rest.length + '</span><span class="spacer"></span><span class="small">клик по строке — сделать этот шаг сейчас</span></div>'
    + '<table class="q">' + act.map((s, i) => row(s, i)).join('') + '</table>'
    + (idle.length ? '<div class="group-label">Делать нечего — прочитать и снять отметку · ' + idle.length + '</div><table class="q">' + idle.map((s, i) => row(s, act.length + i)).join('') + '</table>' : '')
    + '</div>';

  const wr = Object.entries(state.wrong);
  document.getElementById('side').innerHTML = '<div class="card"><div class="ch"><h3>Похоже, уже сделано</h3><span class="badge">' + DATA.stale.length + '</span><span class="spacer"></span><span class="small">отметка «клиент ответил» висит, хотя ответ ушёл</span></div><table class="q">'
    + DATA.stale.map(s => '<tr><td class="a"><div class="t">Снять отметку: ответ уже отправлен</div><div class="c">Клиент писал ' + dt(s.in) + ', ответ ушёл ' + dt(s.out) + ' — «' + esc(s.out_subject) + '».</div></td>'
      + '<td class="k"><a class="code" href="' + URL_BASE + s.id + '" target="_blank">' + esc(s.code) + '</a></td><td class="m">' + esc(s.client) + '</td></tr>').join('') + '</table></div>'
    + (wr.length ? '<div class="card"><div class="ch"><h3>Отмечено как неверное</h3><span class="badge">' + wr.length + '</span><span class="spacer"></span><span class="small">уйдёт РОПу на разбор</span></div><table class="q">'
      + wr.map(([code, why]) => '<tr><td class="a"><div class="t">' + esc(code) + '</div><div class="c">' + esc(why) + '</div></td></tr>').join('') + '</table></div>' : '');
  lucide.createIcons();
}
document.getElementById('meta').textContent = DATA.manager + ' · ' + steps.length + ' шагов из ' + DATA.total + ' открытых заявок · срез ' + dt(DATA.generated_at);
document.getElementById('av').textContent = DATA.manager.split(' ').map(w => w[0]).join('').slice(0, 2);
render();
</script>
</body>
</html>
HTML;

$html = str_replace(['__CSS__', '__DATA__', '__LOGO__'], [$css, $data, $logo], $html);
file_put_contents($dir.'/plan-mockup-v2.html', $html);
echo "ok ".strlen($html)."\n";
