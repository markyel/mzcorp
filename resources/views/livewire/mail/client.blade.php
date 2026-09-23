@php
    use App\Enums\MailFolder;

    $fmtWhen = function ($dt) {
        if (! $dt) return '';
        $c = \Illuminate\Support\Carbon::parse($dt)->timezone(config('app.timezone'));
        if ($c->isToday()) return $c->format('H:i');
        if ($c->isYesterday()) return 'вчера';
        $months = [1=>'янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
        return $c->day.' '.($months[(int) $c->month] ?? '');
    };
    $initials = function ($name, $email) {
        $src = trim((string) ($name ?: $email));
        if ($src === '') return '—';
        $parts = preg_split('/[\s@._-]+/u', $src, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $a = mb_substr($parts[0] ?? '', 0, 1);
        $b = mb_substr($parts[1] ?? '', 0, 1);
        return mb_strtoupper($a.$b) ?: mb_strtoupper(mb_substr($src, 0, 2));
    };
    /**
     * Кого показывать в строке списка. Для исходящих и черновиков — получателя:
     * отправитель там всегда владелец ящика, и список из одинаковых имён
     * бесполезен. Возвращает [имя, адрес, это получатель?, сколько ещё адресатов].
     */
    $counterparty = function ($m) {
        $outbound = ($m->direction?->value === 'outbound') || $m->is_draft;
        $to = collect($m->to_recipients ?? [])->filter(fn ($r) => is_array($r));
        $first = $to->first();
        $name = trim((string) ($first['name'] ?? ''));
        $email = trim((string) ($first['email'] ?? ''));
        if (! $outbound || ($name === '' && $email === '')) {
            return [$m->from_name, $m->from_email, false, 0];
        }
        return [$name, $email, true, max(0, $to->count() - 1)];
    };
    $plural = function (int $n): string {
        $n10 = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) return 'письмо';
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) return 'письма';
        return 'писем';
    };
    $catChip = function ($cat) {
        return match ($cat) {
            'client_request' => ['заявка', 'kp'],
            'supplier_reply' => ['поставщик', 'clar'],
            'post_sale'      => ['пост-продажа', 'invoice'],
            default          => null,
        };
    };
@endphp

{{-- Список обновляется сам. Обычный шаг — 30 секунд: входящие тянутся раз в
     минуту, чаще спрашивать нечего. Но сразу после кнопки «синхронизировать»
     шаг становится 3 секунды на полминуты: сама синхронизация ящика занимает
     около 17 секунд, и при минутном шаге человек видел новые письма только
     через минуту с лишним — отсюда жалобы «кнопка не работает».
     Livewire сам останавливает поллинг, когда вкладка в фоне. Открытое письмо
     и высоты iframe переживают морф (wire:ignore.self), композер — отдельный
     компонент, его поллинг не трогает. --}}
<div class="mailapp" wire:key="mailapp" wire:poll.{{ $this->pollInterval }}>
<style>
/* scoped mail client — на токенах дизайн-системы, без Tailwind-пересборки */
/* Ширина списка писем — тянется мышью, хранится в localStorage (см. mailResizer). */
.mailapp{display:grid;grid-template-columns:240px var(--paneB-w, 400px) 1fr;height:calc(100vh - var(--topbar-h, 56px));min-height:520px;
    overflow:hidden;background:var(--bg-surface);font-family:var(--font-sans);color:var(--fg-1)}
.mailapp *{box-sizing:border-box}
@media(max-width:1100px){.mailapp{grid-template-columns:220px 1fr}.mailapp .paneC{display:none}}
/* Рукоятка ресайза: узкая полоса на границе списка и чтения. Живёт в корне
   .mailapp, а НЕ внутри paneB: у вложенного x-data своя область видимости,
   и запись this.drag из обработчика уходила бы в неё, а не в корневую. */
.mailapp{position:relative}
.mailapp .paneB{position:relative}
.mailapp .bresize{position:absolute;top:0;left:calc(240px + var(--paneB-w, 400px) - 3px);width:7px;height:100%;
    cursor:col-resize;z-index:20;background:transparent}
.mailapp .bresize:hover,.mailapp .bresize.dragging{background:linear-gradient(to right,transparent 2px,var(--sky-500) 2px,var(--sky-500) 5px,transparent 5px)}
/* Пока тянем: курсор-сплиттер по всей странице, без выделения текста, и
   письмо в iframe не перехватывает мышь. */
body.mail-resizing{user-select:none;cursor:col-resize}
body.mail-resizing iframe{pointer-events:none}
@media(max-width:1100px){.mailapp .bresize{display:none}}

/* PANE A */
.mailapp .paneA{background:var(--bg-sidebar);border-right:1px solid var(--border);overflow-y:auto;display:flex;flex-direction:column}
.mailapp .mbx-switch{padding:10px;border-bottom:1px solid var(--border-subtle)}
.mailapp .cur{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--r-md);background:var(--bg-surface)}
.mailapp .cur .dot{width:7px;height:7px;border-radius:999px;background:var(--emerald-600);flex-shrink:0}
.mailapp .cur .dot.err{background:var(--amber-600)}
.mailapp .cur .txt{flex:1;min-width:0}
.mailapp .cur .nm{font:600 12.5px/1.3 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mailapp .cur .em{font:400 11px/1.2 var(--font-mono);color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mailapp .paneA-link{display:block;margin-top:8px;font:500 11.5px/1.3 var(--font-sans);color:var(--sky-700);text-decoration:none}
.mailapp .paneA-link:hover{text-decoration:underline}
.mailapp .flist{padding:8px}
.mailapp .fgroup-label{font:600 10px/1 var(--font-sans);color:var(--fg-3);text-transform:uppercase;letter-spacing:.06em;padding:12px 8px 6px}
.mailapp .fitem{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:var(--r-md);font-size:12.5px;color:var(--fg-2);cursor:pointer;border:none;background:none;width:100%;text-align:left}
.mailapp .fitem:hover{background:var(--bg-hover)}
.mailapp .fitem.active{background:var(--bg-surface);color:var(--fg-1);box-shadow:inset 2px 0 0 var(--accent);font-weight:500}
.mailapp .fitem .lbl{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mailapp .fitem .n{font:500 11.5px/1 var(--font-mono);color:var(--fg-3)}
.mailapp .fitem.active .n{color:var(--fg-1)}
.mailapp .fitem .pill{font:600 10.5px/1.4 var(--font-sans);background:var(--red-50);color:var(--red-700);padding:1px 6px;border-radius:999px}
.mailapp .fitem.err{color:var(--amber-700)}
.mailapp .fsep{height:1px;background:var(--border-subtle);margin:8px 4px}

/* PANE B */
.mailapp .paneB{background:var(--bg-surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.mailapp .blist-top{padding:10px 12px;border-bottom:1px solid var(--border-subtle);display:flex;flex-direction:column;gap:8px}
.mailapp .row1{display:flex;align-items:center;gap:8px}
.mailapp .bsearch{flex:1;position:relative}
.mailapp .bsearch input{width:100%;height:30px;border:1px solid var(--border);border-radius:var(--r-md);background:var(--bg-app);padding:0 10px 0 28px;font:400 12.5px/1 var(--font-sans);color:var(--fg-1);outline:none}
.mailapp .bsearch input:focus{border-color:var(--sky-500)}
.mailapp .bsearch:before{content:"⌕";position:absolute;left:9px;top:7px;color:var(--fg-3);font-size:13px}
.mailapp .compose{height:30px;padding:0 12px;border-radius:var(--r-md);background:var(--accent);color:var(--fg-on-accent);font:600 12.5px/1 var(--font-sans);border:none;cursor:pointer;white-space:nowrap}
.mailapp .fhdr{display:flex;align-items:center;justify-content:space-between;padding:2px;font:500 11.5px/1 var(--font-sans);color:var(--fg-3)}
.mailapp .fhdr b{color:var(--fg-1);font-weight:600;font-feature-settings:'tnum'}
.mailapp .threads{flex:1;overflow-y:auto}
.mailapp .trow{display:grid;grid-template-columns:16px 30px 1fr;column-gap:8px;padding:10px 12px 10px 10px;border-bottom:1px solid var(--border-subtle);cursor:pointer;position:relative}
.mailapp .trow:hover{background:var(--bg-hover)}
/* Выделение писем: чекбокс (виден при наведении/выделении), Shift-диапазон, Ctrl+A, drag&drop в папку. */
.mailapp .trow .chk{width:16px;height:16px;margin-top:7px;border:1.5px solid var(--border-strong);border-radius:4px;background:var(--bg-surface);opacity:0;transition:opacity .12s;flex-shrink:0;cursor:pointer;position:relative}
.mailapp .trow:hover .chk,.mailapp .trow.sel .chk{opacity:1}
.mailapp .trow.sel .chk{background:var(--accent);border-color:var(--accent)}
.mailapp .trow.sel .chk::after{content:'';position:absolute;left:4px;top:1px;width:5px;height:9px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
.mailapp .trow.sel{background:var(--sky-50)}
.mailapp .trow[draggable]{-webkit-user-drag:element}
.dragghost{position:fixed;top:-100px;left:-100px;padding:6px 12px;border-radius:999px;background:var(--fg-1,#0f1419);color:#fff;font:600 12px/1 system-ui,sans-serif;pointer-events:none;z-index:9999}
/* Высота фиксирована и перенос запрещён: закреплённая панель не должна менять
   высоту при выделении, иначе список писем под ней дёргается. Прокрутку по
   горизонтали НЕ включаем — overflow на панели обрезал бы выпадающее меню
   «В папку», которое лежит внутри неё. Содержимое подобрано так, чтобы
   влезать в колонку 400 px; что не влезет при ещё более узкой — обрежет сам
   список (paneB), меню при этом остаётся видимым. */
.mailapp .bulkbar{display:flex;align-items:center;flex-wrap:nowrap;gap:4px;height:38px;padding:0 10px;
    background:var(--sky-50);border-bottom:1px solid var(--border-subtle);font:400 11.5px/1 var(--font-sans);
    color:var(--fg-2)}
.mailapp .bulkbar > *{flex-shrink:0}
/* Счётчик сжимается первым: кнопки действий важнее подписи. */
.mailapp .bulkbar .cnt{margin-right:4px;color:var(--fg-1);white-space:nowrap;flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis}
.mailapp .bulkbar button{height:26px;padding:0 8px;border:1px solid var(--border);background:var(--bg-surface);border-radius:6px;cursor:pointer;color:var(--fg-1);font:500 11.5px/1 var(--font-sans);white-space:nowrap}
.mailapp .bulkbar button:hover{background:var(--bg-hover)}
.mailapp .bulkbar button.link{border:none;background:none;color:var(--sky-700);padding:0 4px}
.mailapp .bulkbar button.x{border:none;background:none;font-size:16px;color:var(--fg-3);padding:0 4px}
.mailapp .bulkbar .spacer{flex:1 1 auto;min-width:4px}
/* Закреплённая панель без выделения: тише фоном, кнопки неактивны. */
.mailapp .bulkbar.idle{background:var(--bg-app)}
.mailapp .bulkbar .idle-hint{color:var(--fg-4)}
.mailapp .bulkbar button:disabled{opacity:.45;cursor:default}
.mailapp .bulkbar button:disabled:hover{background:var(--bg-surface)}
.mailapp .bulkbar button.pin{border:none;background:none;padding:0 4px;filter:grayscale(1);opacity:.5}
.mailapp .bulkbar button.pin.on{filter:none;opacity:1}
.mailapp .bulkbar button.pin:hover{opacity:1}
.mailapp .bulkbar .rte-pop{position:relative;display:inline-flex}
.mailapp .bulkmenu{position:absolute;top:30px;right:0;max-width:min(320px,calc(100vw - 40px));z-index:6;min-width:200px;max-height:320px;overflow-y:auto;background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 30px rgba(15,23,42,.16);padding:4px;display:flex;flex-direction:column}
.mailapp .bulkmenu button{border:none;background:none;text-align:left;height:30px;padding:0 10px;border-radius:5px;font:400 12.5px/1 var(--font-sans);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mailapp .bulkmenu .hint{padding:8px 10px;color:var(--fg-3);font-size:11.5px;white-space:normal}
/* Пользовательские папки в панели A. */
.mailapp .fgroup-folders{display:flex;align-items:center;justify-content:space-between;padding-right:6px}
.mailapp .fgroup-folders .fadd{border:none;background:none;color:var(--fg-3);cursor:pointer;font-size:15px;line-height:1;padding:0 4px;border-radius:4px}
.mailapp .fgroup-folders .fadd:hover{background:var(--bg-hover);color:var(--fg-1)}
.mailapp .fitem.custom{position:relative}
.mailapp .fitem .ficon{color:var(--fg-4);font-size:10px;width:10px;flex-shrink:0}
.mailapp .fitem .factions{display:none;gap:2px}
.mailapp .fitem.custom:hover .factions{display:inline-flex}
.mailapp .fitem .factions button{border:none;background:none;color:var(--fg-3);cursor:pointer;font-size:12px;line-height:1;padding:1px 3px;border-radius:3px}
.mailapp .fitem .factions button:hover{background:var(--bg-surface);color:var(--fg-1)}
.mailapp .fitem.dropover{background:var(--sky-50);box-shadow:inset 0 0 0 1.5px var(--sky-500)}
.mailapp .fnew{display:flex;gap:4px;padding:4px 8px}
.mailapp .fnew input{flex:1;min-width:0;height:26px;border:1px solid var(--border);border-radius:5px;padding:0 6px;font:400 12px/1 var(--font-sans);background:var(--bg-surface);color:var(--fg-1);outline:none}
.mailapp .fnew input:focus{border-color:var(--sky-500)}
.mailapp .fnew button{height:26px;padding:0 8px;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:5px;font:600 11.5px/1 var(--font-sans);cursor:pointer}
.mailapp .fhint{padding:2px 8px 6px;font:400 11px/1.35 var(--font-sans);color:var(--fg-4)}
.mailapp .trow.active{background:var(--bg-selected);box-shadow:inset 3px 0 0 var(--sky-500)}
.mailapp .trow .dot-unread{width:7px;height:7px;border-radius:999px;background:var(--accent);position:absolute;left:3px;top:10px}
.mailapp .trow:hover .dot-unread,.mailapp .trow.sel .dot-unread{display:none}
.mailapp .trow .av{width:30px;height:30px;border-radius:999px;background:var(--neutral-200);color:var(--fg-2);font:600 12px/30px var(--font-sans);text-align:center;flex-shrink:0}
.mailapp .trow .av.org{background:var(--sky-50);color:var(--sky-700)}
.mailapp .trow .body{min-width:0}
.mailapp .trow .l1{display:flex;align-items:baseline;gap:6px}
.mailapp .trow .from{font:500 13px/1.3 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mailapp .trow.unread .from{font-weight:700}
.mailapp .trow .from .more-to{font-weight:400;color:var(--fg-4)}
/* Метки: фильтр над списком, чипы в строке, контекстное меню строки. */
.mailapp .linkbtn{border:none;background:none;padding:0;cursor:pointer;color:var(--accent);font:inherit;text-decoration:underline}
.mailapp .fhdr-right{display:inline-flex;align-items:center;gap:8px}
.mailapp .unreadchip{display:inline-flex;align-items:center;gap:5px;height:19px;padding:0 8px;border-radius:999px;cursor:pointer;
    border:1px solid var(--border-strong);background:var(--bg-surface);color:var(--fg-3);font:500 11px/1 var(--font-sans)}
.mailapp .unreadchip .d{width:6px;height:6px;border-radius:999px;background:var(--border-strong)}
.mailapp .unreadchip:hover{border-color:var(--accent);color:var(--fg-1)}
.mailapp .unreadchip.on{border-color:var(--accent);background:var(--sky-50);color:var(--accent)}
.mailapp .unreadchip.on .d{background:var(--accent)}
.mailapp .lblbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.mailapp .lblchip{display:inline-flex;align-items:center;gap:5px;height:22px;padding:0 8px;border-radius:999px;border:1px solid transparent;
    font:500 11.5px/1 var(--font-sans);cursor:pointer;white-space:nowrap}
.mailapp .lblchip.on{box-shadow:inset 0 0 0 1.5px currentColor}
.mailapp .lblchip .n{font-family:var(--font-mono);opacity:.7}
.mailapp .lblchip.all{background:var(--neutral-100);color:var(--fg-2)}
.mailapp .trow .rowlabels{display:inline-flex;gap:4px;flex-wrap:wrap;margin-top:2px}
.mailapp .trow .rowlabel{display:inline-flex;align-items:center;height:16px;padding:0 6px;border-radius:999px;font:500 10.5px/1 var(--font-sans)}
.mailapp .ctxmenu{position:fixed;z-index:60;min-width:230px;max-height:70vh;overflow-y:auto;background:var(--bg-surface);border:1px solid var(--border);
    border-radius:8px;box-shadow:0 12px 34px rgba(15,23,42,.18);padding:4px}
.mailapp .ctxmenu .sec{padding:6px 10px 3px;font:600 10px/1 var(--font-sans);color:var(--fg-3);text-transform:uppercase;letter-spacing:.06em}
.mailapp .ctxmenu button{display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;text-align:left;height:30px;padding:0 10px;
    border-radius:5px;font:400 12.5px/1 var(--font-sans);color:var(--fg-1);cursor:pointer}
.mailapp .ctxmenu button:hover{background:var(--bg-hover)}
.mailapp .ctxmenu .swatch{width:10px;height:10px;border-radius:999px;flex-shrink:0}
.mailapp .ctxmenu .tick{width:12px;flex-shrink:0;color:var(--sky-600)}
.mailapp .ctxmenu .sep{height:1px;background:var(--border-subtle);margin:4px 2px}
.mailapp .ctxmenu .newlbl{display:flex;gap:4px;padding:4px 6px}
.mailapp .ctxmenu .newlbl input{flex:1;min-width:0;height:26px;border:1px solid var(--border);border-radius:5px;padding:0 6px;
    font:400 12px/1 var(--font-sans);background:var(--bg-surface);color:var(--fg-1);outline:none}
.mailapp .ctxmenu .newlbl input:focus{border-color:var(--sky-500)}
.mailapp .ctxmenu .newlbl button{width:auto;height:26px;padding:0 8px;border:1px solid var(--accent);background:var(--accent);color:#fff;
    border-radius:5px;font:600 11.5px/1 var(--font-sans)}
.mailapp .notice{display:flex;align-items:center;gap:8px;padding:6px 12px;background:var(--emerald-50);border-bottom:1px solid var(--border-subtle);
    font:400 11.5px/1.35 var(--font-sans);color:var(--emerald-700)}
.mailapp .notice button{border:none;background:none;color:var(--fg-3);cursor:pointer;font-size:14px;line-height:1;padding:0 2px}
.mailapp .syncbtn{height:30px;padding:0 10px;border:1px solid var(--border);background:var(--bg-surface);border-radius:var(--r-md);cursor:pointer;
    color:var(--fg-2);font:500 12.5px/1 var(--font-sans);white-space:nowrap}
.mailapp .syncbtn:hover{background:var(--bg-hover);color:var(--fg-1)}
.mailapp .syncbtn[disabled]{opacity:.6;cursor:default}
/* Панель управления метками прижата к краям самого списка, а не к шестерёнке:
   шестерёнка стоит в середине строки, и панель любой ширины уезжала бы за
   правый край — paneB обрезает всё, что вышло за него (overflow:hidden). */
.mailapp .blist-top{position:relative}
.mailapp .lblmgr{position:absolute;top:100%;left:12px;right:12px;margin-top:2px;
    width:auto;min-width:0;max-width:none;padding:6px;z-index:7}
.mailapp .lblbar .mgrwrap{display:inline-flex}
.mailapp .lblmgr .mgrhdr{padding:4px 6px 6px;font:600 11.5px/1 var(--font-sans);color:var(--fg-2)}
.mailapp .lblmgr .lblrow{display:flex;flex-wrap:wrap;align-items:center;gap:5px;padding:5px 4px;border-top:1px solid var(--border-subtle)}
.mailapp .lblmgr .lblrow input{flex:1 1 100%;min-width:0;height:27px;border:1px solid var(--border);border-radius:5px;padding:0 6px;
    font:400 12px/1 var(--font-sans);background:var(--bg-surface);color:var(--fg-1);outline:none}
.mailapp .lblmgr .lblrow input:focus{border-color:var(--sky-500)}
.mailapp .lblmgr .lblrow .dot{width:15px;height:15px;border-radius:999px;border:2px solid transparent;cursor:pointer;padding:0;flex-shrink:0}
.mailapp .lblmgr .lblrow .dot.on{border-color:var(--fg-1)}
.mailapp .lblmgr .lblrow .del{margin-left:auto;height:24px;border:1px solid var(--border);background:var(--bg-surface);color:var(--fg-2);
    cursor:pointer;font:500 11.5px/1 var(--font-sans);padding:0 8px;border-radius:5px;white-space:nowrap}
.mailapp .lblmgr .lblrow .del:hover{color:var(--accent);border-color:var(--red-300)}
.mailapp .trow .when{font:500 11px/1 var(--font-mono);color:var(--fg-3);flex-shrink:0}
.mailapp .trow.unread .when{color:var(--fg-1);font-weight:600}
.mailapp .trow .l2{display:flex;align-items:baseline;gap:6px;margin-top:2px}
.mailapp .trow .subj{font:500 12.5px/1.35 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mailapp .trow.unread .subj{font-weight:600}
.mailapp .trow .l3{display:flex;align-items:center;gap:6px;margin-top:3px}
.mailapp .trow .snip{font:400 12px/1.3 var(--font-sans);color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mailapp .trow .metaicons{display:flex;align-items:center;gap:6px;flex-shrink:0}
/* Флажок живёт под кружком, а не в строке метаданных: там он терялся среди
   скрепки и номера заявки. Непомеченное письмо флажка не показывает вовсе —
   проступает при наведении на строку, как и галочка выбора слева. */
.mailapp .trow .avcol{display:flex;flex-direction:column;align-items:center;gap:7px;min-width:0}
.mailapp .trow .flagbtn{border:none;background:none;cursor:pointer;font-size:16px;color:var(--fg-4);padding:0;line-height:1;opacity:0;transition:opacity .12s}
.mailapp .trow:hover .flagbtn{opacity:1}
.mailapp .trow .flagbtn:hover{color:var(--amber-600)}
.mailapp .trow .flagbtn.on{color:var(--amber-600);opacity:1}
.mailapp .trow .clip{color:var(--fg-3);font-size:12px}
.mailapp .trow .reqchip{font:600 10.5px/1.4 var(--font-mono);background:var(--violet-50);color:var(--violet-700);padding:1px 6px;border-radius:4px}
.mailapp .trow .onecchip{font:600 10.5px/1.4 var(--font-mono);background:var(--emerald-50);color:var(--emerald-700);padding:1px 6px;border-radius:4px;white-space:nowrap}
/* Шапка списка в режиме «письма заявки» (?request=). */
.mailapp .sscope{display:flex;align-items:center;gap:6px;font:400 11.5px/1 var(--font-sans);color:var(--fg-3)}
.mailapp .sscope select{flex:1;min-width:0;height:26px;border:1px solid var(--border);border-radius:6px;background-color:var(--bg-surface);background-image:none;-webkit-appearance:none;appearance:none;color:var(--fg-1);font:500 11.5px/1 var(--font-sans);padding:0 22px 0 8px;line-height:24px}
.mailapp .sscope .selwrap{flex:1;position:relative;display:flex;min-width:0}
.mailapp .sscope .selwrap:after{content:"▾";position:absolute;right:8px;top:5px;font-size:12px;color:var(--fg-3);pointer-events:none}
.mailapp .trow .fchip{font:500 10.5px/1.4 var(--font-sans);background:var(--sky-50);color:var(--sky-700,#0369a1);padding:1px 6px;border-radius:4px;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis}
.mailapp .fhdr .reqfilter{display:inline-flex;align-items:center;gap:6px;color:var(--fg-2)}
.mailapp .fhdr .reqfilter .code{font:600 11px/1.4 var(--font-mono);background:var(--violet-50);color:var(--violet-700);padding:1px 6px;border-radius:4px;text-decoration:none}
.mailapp .fhdr .reqfilter .onec{font:600 10.5px/1.4 var(--font-mono);background:var(--emerald-50);color:var(--emerald-700);padding:1px 6px;border-radius:4px}
.mailapp .fhdr .reqfilter button{border:none;background:none;color:var(--fg-3);cursor:pointer;font-size:14px;line-height:1;padding:0 2px}
.mailapp .fhdr .reqfilter button:hover{color:var(--fg-1)}
/* Заявка «Клиент ждёт счёт»: тёплый цвет текста строки + полоска слева. */
.mailapp .trow.awaiting-inv .from,.mailapp .trow.awaiting-inv .subj{color:var(--amber-700,#b45309)}
.mailapp .trow.awaiting-inv .snip{color:var(--amber-600,#d97706)}
.mailapp .trow.awaiting-inv{box-shadow:inset 3px 0 0 var(--amber-600,#d97706)}
.mailapp .trow .rubchip{font:700 11px/1.4 var(--font-sans);background:var(--amber-50,#fff7ed);color:var(--amber-700,#b45309);padding:1px 6px;border-radius:4px;white-space:nowrap}
/* «КП готово» — система посчитала предложение, его осталось проверить. */
.mailapp .trow .aqchip{font:600 10.5px/1.4 var(--font-sans);background:var(--emerald-50);color:var(--emerald-700);padding:1px 6px;border-radius:4px;white-space:nowrap}
.mailapp .trow .catchip{font:500 10.5px/1.3 var(--font-sans);padding:1px 6px;border-radius:999px}
.mailapp .trow .catchip.kp{background:var(--sky-50);color:var(--sky-700)}
.mailapp .trow .catchip.invoice{background:var(--emerald-50);color:var(--emerald-700)}
.mailapp .trow .catchip.clar{background:var(--amber-50);color:var(--amber-700)}
.mailapp .blist-foot{padding:10px 12px;border-top:1px solid var(--border-subtle);text-align:center}
.mailapp .blist-foot button{font:500 12px/1 var(--font-sans);color:var(--sky-700);background:none;border:none;cursor:pointer}
.mailapp .empty{padding:40px 20px;text-align:center;color:var(--fg-3);font-size:12.5px}

/* PANE C */
.mailapp .paneC{background:var(--bg-surface);display:flex;flex-direction:column;overflow:hidden}
.mailapp .paneC .empty-read{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--fg-3);gap:10px}
.mailapp .paneC .empty-read .big{font-size:34px;color:var(--fg-4)}
.mailapp .chead{padding:16px 24px 12px;border-bottom:1px solid var(--border)}
.mailapp .chead .top1{display:flex;align-items:flex-start;gap:12px}
.mailapp .chead h1{margin:0;font:600 17px/1.35 var(--font-sans);color:var(--fg-1);flex:1;letter-spacing:-.005em}
.mailapp .chead .menu{border:none;background:none;color:var(--fg-3);font-weight:700;letter-spacing:1px;cursor:pointer;padding:4px}
.mailapp .chead .meta{font:400 12px/1.4 var(--font-sans);color:var(--fg-3);margin-top:5px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.mailapp .chead .sortbtn{height:22px;padding:0 8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-surface);color:var(--fg-2);font:500 11px/1 var(--font-sans);cursor:pointer;white-space:nowrap}
.mailapp .chead .sortbtn:hover{background:var(--bg-hover);color:var(--fg-1)}
.mailapp .chead .reqlink{display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:8px 12px;background:var(--violet-50);border:1px solid var(--violet-600);border-radius:var(--r-md);font-size:12.5px}
.mailapp .chead .reqlink .code{font-family:var(--font-mono);font-weight:600;color:var(--violet-700)}
/* Письмо без заявки — та же плашка, но нейтральная: это не связь, а её отсутствие. */
.mailapp .chead .reqlink.promote{background:var(--bg-surface);border-color:var(--border-strong);color:var(--fg-3);gap:12px}
.mailapp .chead .reqlink .onec{font-family:var(--font-mono);font-weight:600;color:var(--emerald-700);margin-left:6px}
.mailapp .chead .reqlink .st{color:var(--violet-700)}
.mailapp .chead .decisions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.mailapp .chead .dec{display:inline-flex;align-items:center;gap:6px;font:400 11.5px/1.3 var(--font-sans);color:var(--fg-2);background:var(--bg-app);border:1px solid var(--border);border-radius:6px;padding:3px 8px}
.mailapp .chead .dec b{font-weight:500;color:var(--fg-1)}
.mailapp .chead .dec .mono{font:600 11px/1.3 var(--font-mono);color:var(--violet-700)}
.mailapp .chead .dec .when{color:var(--fg-3);font-family:var(--font-mono);font-size:10.5px}
.mailapp .chead .dec.created b{color:var(--emerald-700)}
.mailapp .chead .dec.post_sale b,.mailapp .chead .dec.supplier b{color:var(--amber-700,#b45309)}
.mailapp .chead .dec.skipped b,.mailapp .chead .dec.none b{color:var(--fg-3)}
.mailapp .chead .reqlink .spacer{flex:1}
.mailapp .chead .reqlink a{color:var(--violet-700);font-weight:600;text-decoration:none;border-bottom:1px dashed currentColor}
/* Полоса «ещё письма в переписке»: открыто ровно выбранное письмо, соседние —
   свёрнутым списком, клик по строке открывает её. */
.mailapp .tstrip{border-bottom:1px solid var(--border-subtle);background:var(--bg-app)}
.mailapp .tstrip-head{display:flex;align-items:center;gap:6px;width:100%;border:none;background:none;cursor:pointer;
    padding:7px 24px;font:500 12px/1 var(--font-sans);color:var(--fg-2);text-align:left}
.mailapp .tstrip-head:hover{color:var(--fg-1)}
.mailapp .tstrip-list{padding:0 12px 6px}
.mailapp .tstrip-item{display:flex;align-items:center;gap:8px;width:100%;border:none;background:none;cursor:pointer;
    padding:5px 12px;border-radius:6px;font:400 12px/1.3 var(--font-sans);color:var(--fg-2);text-align:left}
.mailapp .tstrip-item:hover{background:var(--bg-hover)}
.mailapp .tstrip-item.cur{background:var(--bg-selected);color:var(--fg-1);font-weight:500}
.mailapp .tstrip-item .dir{width:10px;flex-shrink:0;color:var(--fg-4)}
.mailapp .tstrip-item .who{width:180px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mailapp .tstrip-item .sub{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--fg-3)}
.mailapp .tstrip-item .when{flex-shrink:0;font:400 11px/1 var(--font-mono);color:var(--fg-4)}
.mailapp .cbody{flex:1;overflow-y:auto;padding:0 24px}
.mailapp .msg{border-bottom:1px solid var(--border-subtle);padding:14px 0 20px}
.mailapp .msg.draft{background:var(--warn-soft, #f6ecd6);margin:0 -24px;padding:14px 24px 16px;border-left:3px solid var(--warn, #9c7420)}
.mailapp .msg.draft .av{background:var(--warn, #9c7420);color:#fff}
.mailapp .draft-badge{font:600 10px/1.4 var(--font-mono);letter-spacing:.04em;text-transform:uppercase;color:var(--warn, #9c7420);background:var(--bg-surface);border:1px solid var(--warn, #9c7420);padding:1px 6px;border-radius:4px;margin-left:6px}
.mailapp .draft-actions{display:flex;gap:8px;margin-top:12px}
.mailapp .draft-actions button{height:30px;padding:0 14px;border-radius:var(--r-md);font:500 12.5px/1 var(--font-sans);cursor:pointer;border:1px solid var(--border-strong);background:var(--bg-surface);color:var(--fg-1)}
.mailapp .draft-actions .da-primary{background:var(--accent);color:#fff;border-color:var(--accent);font-weight:600}
.mailapp .draft-actions .da-del{color:var(--crit, #b0432e);border-color:transparent;background:none}
.mailapp .msg:last-child{border-bottom:none}
.mailapp .mhead{display:flex;align-items:flex-start;gap:10px;margin-bottom:12px}
.mailapp .mhead .av{width:36px;height:36px;border-radius:999px;background:var(--neutral-200);color:var(--fg-2);font:600 13px/36px var(--font-sans);text-align:center;flex-shrink:0}
.mailapp .msg.outbound .mhead .av{background:var(--sky-50);color:var(--sky-700)}
.mailapp .mhead .who{flex:1;min-width:0}
.mailapp .mhead .nm{font:600 13.5px/1.3 var(--font-sans);color:var(--fg-1)}
.mailapp .mhead .em{font:400 12px/1.3 var(--font-mono);color:var(--fg-3)}
.mailapp .mhead .tocc{font:400 11.5px/1.4 var(--font-sans);color:var(--fg-3);margin-top:3px}
.mailapp .mhead .when{font:500 12px/1 var(--font-mono);color:var(--fg-3);flex-shrink:0;white-space:nowrap}
.mailapp .msg-acts{display:inline-flex;gap:4px;flex-shrink:0;opacity:.5;transition:opacity .12s}
.mailapp .msg:hover .msg-acts{opacity:1}
.mailapp .msg-acts button{border:1px solid var(--border);background:var(--bg-surface);border-radius:5px;padding:0 9px;height:24px;cursor:pointer;color:var(--fg-2);font:500 11.5px/1 var(--font-sans);white-space:nowrap}
.mailapp .msg-acts button:hover{background:var(--bg-hover);color:var(--accent);border-color:var(--accent)}
.mailapp .cfoot-hint{font-size:11px;color:var(--fg-3);margin-top:8px}
.mailapp .msg.outbound{background:var(--bg-surface-2);margin:0 -24px;padding:14px 24px 20px}
.mailapp .mbody iframe{width:100%;display:block;border:0;background:var(--bg-surface);border-radius:var(--r-md)}
.mailapp .mbody pre{white-space:pre-wrap;font:400 13px/1.55 var(--font-sans);color:var(--fg-1);margin:0}
.mailapp .photos{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.mailapp .photo{display:block;width:132px;height:132px;border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--surface-2);cursor:zoom-in;transition:border-color .12s}
.mailapp .photo:hover{border-color:var(--accent)}
.mailapp .photo img{width:100%;height:100%;object-fit:cover;display:block}
.mailapp .attachments{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.mailapp .attachments a.att{text-decoration:none;transition:border-color .12s}
.mailapp .attachments a.att:hover{border-color:var(--accent)}
.mailapp .att{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:var(--r-md);background:var(--bg-surface);text-decoration:none}
.mailapp .att .ico{width:28px;height:32px;border-radius:4px;background:var(--red-50);border:1px solid var(--red-300,#fca5a5);color:var(--red-700);display:flex;align-items:center;justify-content:center;font:700 8px/1 var(--font-sans);flex-shrink:0}
.mailapp .att .ico.img{background:var(--sky-50);color:var(--sky-700)}
.mailapp .att .fn{font:500 12px/1.3 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
.mailapp .att .sz{font:400 10.5px/1 var(--font-sans);color:var(--fg-3)}
.mailapp .cfoot{border-top:1px solid var(--border);padding:14px 24px;background:var(--bg-surface)}
.mailapp .replybtns{display:flex;gap:8px}
.mailapp .replybtns button{height:32px;padding:0 14px;border-radius:var(--r-md);font:500 12.5px/1 var(--font-sans);border:1px solid var(--border-strong);background:var(--bg-surface);color:var(--fg-1);cursor:pointer}
.mailapp .replybtns button.primary{background:var(--accent);color:var(--fg-on-accent);border-color:var(--accent);font-weight:600}
</style>

    {{-- ══════════ PANE A — ящики + папки ══════════ --}}
    <div class="paneA"
         x-data="{
            newFolderFor: null, newFolderName: '',
            dragOver(ev) { ev.currentTarget.classList.add('dropover'); },
            dragLeave(ev) { ev.currentTarget.classList.remove('dropover'); },
            drop(ev, folderId) {
                ev.currentTarget.classList.remove('dropover');
                let ids = [];
                try { ids = JSON.parse(ev.dataTransfer.getData('text/plain') || '[]'); } catch (e) {}
                if (Array.isArray(ids) && ids.length) { $wire.moveToFolder(ids, folderId); }
            },
            startNew(parentId) { this.newFolderFor = parentId ?? 0; this.newFolderName = ''; this.$nextTick(() => this.$refs['nf' + (parentId ?? 0)]?.focus()); },
            submitNew() { const n = this.newFolderName.trim(); if (n) { $wire.createFolder(n, this.newFolderFor || null); } this.newFolderFor = null; this.newFolderName = ''; },
            rename(id, current) { const n = prompt('Новое имя папки:', current); if (n && n.trim() && n.trim() !== current) { $wire.renameFolder(id, n.trim()); } },
            remove(id, name) { if (confirm('Удалить папку «' + name + '»? Письма вернутся во «Входящие», подпапки поднимутся на уровень выше.')) { $wire.deleteFolder(id); } }
         }">
        @php $groups = $this->mailboxGroups; $cur = $groups['current']; @endphp
        <div class="mbx-switch">
            <div class="cur">
                <span class="dot {{ ($cur['error'] ?? false) ? 'err' : '' }}"></span>
                <div class="txt">
                    <div class="nm">{{ $cur['name'] ?? 'Ящик' }}@if(($cur['kind'] ?? '')==='shared') · общий @elseif(($cur['kind'] ?? '')==='delegated') · делег. @endif</div>
                    <div class="em">{{ $cur['email'] ?? '' }}</div>
                </div>
            </div>
            @if(auth()->user()?->hasAnyRole(['head_of_sales', 'secretary', 'director', 'admin']))
                <a href="{{ route('mail.index') }}" wire:navigate class="paneA-link">Вся почта · обзор всех ящиков →</a>
            @endif
        </div>

        <div class="flist">
            @foreach($this->folders as $f)
                <button type="button" wire:key="fld-{{ $f['key'] }}"
                        wire:click="selectFolder('{{ $f['key'] }}')"
                        class="fitem {{ $f['active'] ? 'active' : '' }}"
                        @if($f['key'] === 'inbox') @dragover.prevent="dragOver($event)" @dragleave="dragLeave($event)" @drop.prevent="drop($event, null)" @endif>
                    <span class="lbl">{{ $f['label'] }}</span>
                    @if($f['count'])
                        @if($f['unread'])<span class="pill">{{ $f['count'] }}</span>@else<span class="n">{{ $f['count'] }}</span>@endif
                    @endif
                </button>
            @endforeach

            {{-- Пользовательские папки выбранного ящика: дерево, создание, drop-цели. --}}
            @php $customId = $this->customFolderId(); @endphp
            <div class="fgroup-label fgroup-folders">
                <span>Папки</span>
                <button type="button" class="fadd" @click="startNew(null)" title="Новая папка">+</button>
            </div>
            <div class="fnew" x-show="newFolderFor === 0" x-cloak>
                <input type="text" x-ref="nf0" x-model="newFolderName" maxlength="80" placeholder="Имя папки"
                       @keydown.enter.prevent="submitNew()" @keydown.escape="newFolderFor = null">
                <button type="button" @click="submitNew()">ОК</button>
            </div>
            @forelse($this->customFolders as $cf)
                <div wire:key="cf-{{ $cf['id'] }}" class="fitem custom {{ $customId === $cf['id'] ? 'active' : '' }}"
                     style="padding-left: {{ 8 + $cf['depth'] * 14 }}px"
                     wire:click="selectFolder('{{ $cf['key'] }}')"
                     @dragover.prevent="dragOver($event)" @dragleave="dragLeave($event)" @drop.prevent="drop($event, {{ $cf['id'] }})">
                    <span class="ficon">{{ $cf['depth'] > 0 ? '└' : '▸' }}</span>
                    <span class="lbl" title="{{ $cf['name'] }}">{{ $cf['name'] }}</span>
                    <span class="factions">
                        @if($cf['depth'] + 1 < \App\Models\MailboxFolder::MAX_DEPTH)
                            <button type="button" @click.stop="startNew({{ $cf['id'] }})" title="Подпапка">+</button>
                        @endif
                        <button type="button" @click.stop="rename({{ $cf['id'] }}, @js($cf['name']))" title="Переименовать">✎</button>
                        <button type="button" @click.stop="remove({{ $cf['id'] }}, @js($cf['name']))" title="Удалить папку">×</button>
                    </span>
                    @if($cf['unread'])<span class="pill">{{ $cf['unread'] }}</span>@elseif($cf['total'])<span class="n">{{ $cf['total'] }}</span>@endif
                </div>
                <div class="fnew" x-show="newFolderFor === {{ $cf['id'] }}" x-cloak style="padding-left: {{ 8 + ($cf['depth'] + 1) * 14 }}px">
                    <input type="text" x-ref="nf{{ $cf['id'] }}" x-model="newFolderName" maxlength="80" placeholder="Имя подпапки"
                           @keydown.enter.prevent="submitNew()" @keydown.escape="newFolderFor = null">
                    <button type="button" @click="submitNew()">ОК</button>
                </div>
            @empty
                <div class="fhint" x-show="newFolderFor !== 0">Папок нет — создайте «+» и перетаскивайте письма.</div>
            @endforelse

            @if(!empty($groups['shared']) || !empty($groups['delegated']) || !empty($groups['personalOthers']))
                <div class="fgroup-label">Ящики</div>
                @foreach(array_merge($groups['personalOthers'], $groups['shared']) as $mb)
                    <button type="button" wire:key="mbx-{{ $mb['id'] }}" wire:click="selectMailbox({{ $mb['id'] }})" class="fitem {{ $mb['id'] == $selectedMailboxId ? 'active' : '' }}">
                        <span class="lbl">{{ $mb['kind']==='shared' ? 'Общий · '.$mb['email'] : $mb['name'] }}</span>
                        @if($mb['unread'])<span class="pill">{{ $mb['unread'] }}</span>@endif
                    </button>
                @endforeach
                @foreach($groups['delegated'] as $mb)
                    <button type="button" wire:key="mbx-{{ $mb['id'] }}" wire:click="selectMailbox({{ $mb['id'] }})" class="fitem {{ $mb['id'] == $selectedMailboxId ? 'active' : '' }} {{ $mb['error'] ? 'err' : '' }}">
                        <span class="lbl">{{ $mb['name'] }} (делег.)</span>
                        @if($mb['unread'])<span class="pill">{{ $mb['unread'] }}</span>@endif
                    </button>
                @endforeach
            @endif
        </div>
    </div>

    {{-- ══════════ PANE B — список тредов ══════════ --}}
    <div class="paneB"
         x-data="{
            sel: [], last: null, moveOpen: false, mgrOpen: false,
            /* Контекстное меню строки: метки и быстрые действия (как в Яндексе). */
            menu: { open: false, x: 0, y: 0, id: null, labels: [] },
            newLabel: '',
            openMenu(id, labels, ev) {
                /* Правый клик по невыделенной строке работает по ней одной —
                   иначе легко повесить метку не на то, что видишь. */
                if (! this.has(id)) { this.sel = []; this.last = id; }
                this.menu = {
                    open: true, id, labels: labels,
                    x: Math.min(ev.clientX, window.innerWidth - 250),
                    y: Math.min(ev.clientY, window.innerHeight - 320),
                };
                this.newLabel = '';
            },
            closeMenu() { this.menu.open = false; },
            /* На что действуем: на выделение, если правый клик был по нему. */
            /* Копия, а не прокси Alpine: в $wire реактивный массив уезжает пустым. */
            targets() { return (this.sel.length > 1 && this.has(this.menu.id)) ? [...this.sel] : [this.menu.id]; },
            hasLabel(labelId) { return this.menu.labels.includes(labelId); },
            toggleLabel(labelId) {
                const ids = this.targets();
                if (ids.length > 1) { $wire.labelMany(ids, labelId, ! this.hasLabel(labelId)); }
                else { $wire.toggleLabel(this.menu.id, labelId); }
                this.closeMenu();
            },
            addLabel() {
                const name = this.newLabel.trim();
                if (! name) return;
                $wire.createLabel(name, 'sky', this.targets());
                this.newLabel = '';
                this.closeMenu();
            },
            ids() { return [...$el.querySelectorAll('.trow[data-id]')].map(e => Number(e.dataset.id)); },
            has(id) { return this.sel.includes(id); },
            toggle(id, ev) {
                const all = this.ids();
                if (ev && ev.shiftKey && this.last !== null && all.includes(this.last)) {
                    const a = all.indexOf(this.last), b = all.indexOf(id);
                    const range = all.slice(Math.min(a, b), Math.max(a, b) + 1);
                    this.sel = [...new Set([...this.sel, ...range])];
                } else if (this.has(id)) {
                    this.sel = this.sel.filter(x => x !== id);
                } else {
                    this.sel = [...this.sel, id];
                }
                this.last = id;
            },
            all() { this.sel = this.ids(); },
            clear() { this.sel = []; this.moveOpen = false; this.menu.open = false; },
            editable(t) { return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable); },
            onKey(e) {
                if (this.editable(e.target)) return;
                if ((e.ctrlKey || e.metaKey) && (e.code === 'KeyA' || e.key.toLowerCase() === 'a')) { e.preventDefault(); this.all(); }
                else if (e.key === 'Escape' && this.menu.open) { this.closeMenu(); }
                else if (e.key === 'Escape' && this.sel.length) { this.clear(); }
            },
            dragStart(id, ev) {
                if (! this.has(id)) { this.sel = [id]; this.last = id; }
                ev.dataTransfer.setData('text/plain', JSON.stringify(this.sel));
                ev.dataTransfer.effectAllowed = 'move';
                const g = document.createElement('div');
                g.className = 'dragghost';
                g.textContent = this.sel.length + ' ' + (this.sel.length === 1 ? 'письмо' : (this.sel.length < 5 ? 'письма' : 'писем'));
                document.body.appendChild(g);
                ev.dataTransfer.setDragImage(g, 10, 10);
                setTimeout(() => g.remove(), 0);
            }
         }"
         @keydown.window="onKey($event)"
         @mail-selection-clear.window="clear()">
        <div class="blist-top">
            <div class="row1">
                <div class="bsearch">
                    <input type="text" placeholder="Поиск в этом ящике…" wire:model.live.debounce.400ms="search">
                </div>
                {{-- Принудительная синхронизация: обычно почта подтягивается сама
                     раз в 2 минуты, но иногда письмо нужно прямо сейчас. --}}
                <button type="button" class="syncbtn" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow"
                        title="Забрать письма из ящика прямо сейчас{{ $syncedAt ? ' · последний раз в '.$syncedAt : '' }}">
                    <span wire:loading.remove wire:target="syncNow">↻</span>
                    <span wire:loading wire:target="syncNow">…</span>
                </button>
                <button class="compose" wire:click="compose({{ (int) $selectedMailboxId }})">Написать</button>
            </div>

            {{-- Фильтр по меткам: срез поверх текущей папки, письмо остаётся на месте. --}}
            @if(count($this->labels))
                @php $lcounts = $this->labelCounts; @endphp
                <div class="lblbar">
                    <button type="button" class="lblchip all {{ $labelId === null ? 'on' : '' }}"
                            wire:click="filterByLabel(null)">Все письма</button>
                    @foreach($this->labels as $label)
                        <button type="button" wire:key="lf-{{ $label->id }}"
                                class="lblchip {{ $labelId === $label->id ? 'on' : '' }}"
                                style="background:{{ $label->bg() }};color:{{ $label->fg() }}"
                                wire:click="filterByLabel({{ $labelId === $label->id ? 'null' : $label->id }})">
                            {{ $label->name }}
                            @if(($lcounts[$label->id] ?? 0) > 0)<span class="n">{{ $lcounts[$label->id] }}</span>@endif
                        </button>
                    @endforeach

                    {{-- Управление словарём меток: переименовать, перекрасить, удалить. --}}
                    {{-- Обёртка нужна ради @click.outside: он висит на ней, а не на
                         самой панели — иначе клик по шестерёнке считался бы «снаружи»
                         и закрывал панель в тот же тик, в который она открывается. --}}
                    <span class="mgrwrap" @click.outside="mgrOpen = false">
                        <button type="button" class="lblchip all" @click="mgrOpen = ! mgrOpen" title="Управление метками">⚙</button>
                        <div class="bulkmenu lblmgr" x-show="mgrOpen" x-cloak>
                            <div class="mgrhdr">Управление метками · имя и Enter, цвет — кружком</div>
                            @foreach($this->labels as $label)
                                <div class="lblrow" wire:key="lm-{{ $label->id }}">
                                    <input type="text" value="{{ $label->name }}" maxlength="{{ \App\Models\MailLabel::NAME_MAX }}"
                                           @keydown.enter.prevent="$wire.renameLabel({{ $label->id }}, $event.target.value)"
                                           @keydown.stop title="Измените имя и нажмите Enter">
                                    @foreach(\App\Models\MailLabel::COLORS as $key => $c)
                                        <button type="button" class="dot {{ $label->color === $key ? 'on' : '' }}"
                                                style="background:{{ $c[1] }}" title="Цвет: {{ $key }}"
                                                wire:click="recolorLabel({{ $label->id }}, '{{ $key }}')"></button>
                                    @endforeach
                                    <button type="button" class="del" title="Удалить метку у всех писем"
                                            wire:click="deleteLabel({{ $label->id }})"
                                            wire:confirm="Удалить метку «{{ $label->name }}»? Она исчезнет со всех писем.">Удалить</button>
                                </div>
                            @endforeach
                            <div class="hint">Метки личные: ваш набор, коллеги его не видят.</div>
                        </div>
                    </span>
                </div>
            @endif
            @if(trim($search) !== '' && ! $requestId)
                {{-- Поиск идёт по всем папкам ящика; здесь можно сузить до одной. --}}
                <div class="sscope">
                    <span>Искать в</span>
                    <span class="selwrap"><select wire:model.live="searchIn">
                        <option value="all">всех папках</option>
                        <option value="inbox">только во «Входящих»</option>
                        @foreach($this->customFolders as $cf)
                            <option value="f:{{ $cf['id'] }}">{{ str_repeat('· ', $cf['depth']) }}{{ $cf['name'] }}</option>
                        @endforeach
                    </select></span>
                </div>
            @endif
            <div class="fhdr">
                @if($this->filterRequest)
                    <span class="reqfilter" title="{{ $this->filterRequest->subject }}">
                        Письма заявки
                        <a href="{{ route('requests.show', $this->filterRequest->id) }}" class="code">{{ $this->filterRequest->internal_code }}</a>
                        @if($this->filterRequest->onec_number)<span class="onec">1С {{ $this->filterRequest->onec_number }}</span>@endif
                        <button type="button" wire:click="clearRequestFilter" title="Снять фильтр — вернуться к ящику">×</button>
                    </span>
                @else
                    <span>{{ $this->searchScopeLabel ?? $this->currentFolderLabel }}</span>
                @endif
                {{-- Срез «только непрочитанные» стоит у счётчика писем: он же
                     этот счётчик и меняет, а отдельной строки не стоит. --}}
                <span class="fhdr-right">
                    <button type="button" class="unreadchip {{ $unreadOnly ? 'on' : '' }}"
                            wire:click="toggleUnreadOnly"
                            title="{{ $unreadOnly ? 'Показать все письма' : 'Оставить только непрочитанные' }}">
                        <span class="d"></span>{{ $unreadOnly ? 'Только непрочитанные' : 'Непрочитанные' }}
                    </button>
                    <span><b>{{ number_format($this->totalCount, 0, '.', ' ') }}</b> писем</span>
                </span>
            </div>
        </div>

        @if($notice)
            <div class="notice">
                <span>{{ $notice }}</span>
                <span style="flex:1"></span>
                <button type="button" wire:click="dismissNotice" title="Скрыть">×</button>
            </div>
        @endif

        {{-- Панель массовых действий (видна при выделении). --}}
        {{-- Панель массовых действий. По умолчанию всплывает при выделении;
             закреплённая (личная настройка) висит всегда и без выделения
             просто неактивна — чтобы действия были на виду. --}}
        <div class="bulkbar {{ $bulkBarPinned ? 'pinned' : '' }}"
             :class="{ idle: ! sel.length }"
             @if(! $bulkBarPinned) x-show="sel.length" x-cloak @endif>
            <span class="cnt">
                <template x-if="sel.length"><span>Выбрано <b x-text="sel.length"></b></span></template>
                <template x-if="! sel.length"><span class="idle-hint">Выберите письма</span></template>
            </span>
            {{-- В $wire уходит КОПИЯ массива: sel — реактивный прокси Alpine,
                 и Livewire доезжал до сервера с пустым списком (действия молча
                 ничего не делали). Спред снимает прокси. --}}
            <button type="button" :disabled="! sel.length" @click="$wire.markManyRead([...sel])" title="Пометить прочитанными">Прочитано</button>
            <button type="button" :disabled="! sel.length" @click="$wire.markManyUnread([...sel])" title="Пометить непрочитанными">Непрочитано</button>
            <span class="rte-pop">
                <button type="button" :disabled="! sel.length" @click="moveOpen = !moveOpen">В папку ▾</button>
                <div class="bulkmenu" x-show="moveOpen" x-cloak @click.outside="moveOpen = false">
                    <button type="button" @click="$wire.moveToFolder([...sel], null); moveOpen = false">Входящие</button>
                    @foreach($this->customFolders as $cf)
                        <button type="button" wire:key="bm-{{ $cf['id'] }}" style="padding-left: {{ 10 + $cf['depth'] * 12 }}px"
                                @click="$wire.moveToFolder([...sel], {{ $cf['id'] }}); moveOpen = false">{{ $cf['name'] }}</button>
                    @endforeach
                    @if(empty($this->customFolders))<div class="hint">Папок ещё нет — создайте в списке слева.</div>@endif
                </div>
            </span>
            <span class="spacer"></span>
            <button type="button" class="link" @click="all()" title="Выбрать все письма на странице">Все</button>
            <button type="button" class="pin {{ $bulkBarPinned ? 'on' : '' }}" wire:click="toggleBulkBarPin"
                    title="{{ $bulkBarPinned ? 'Открепить: панель будет появляться только при выделении' : 'Закрепить панель — останется на виду и без выделения' }}">📌</button>
            <button type="button" class="x" :disabled="! sel.length" @click="clear()" title="Снять выделение">×</button>
        </div>

        <div class="threads">
            @php $autoQuotes = $this->autoQuotes; @endphp
            @forelse($this->threads->take($perPage) as $m)
                @php
                    $unread = $m->my_read_at === null && $m->direction?->value === 'inbound';
                    $cat = $catChip($m->category);
                    $isOrg = (bool) $m->related_request_id;
                    // Заявка в «Клиент ждёт счёт» — строка подсвечивается (цвет текста + маркер ₽).
                    $rrs = null;
                    if ($m->related_request_id && $m->relatedRequest) {
                        $rrs = $m->relatedRequest->status instanceof \App\Enums\RequestStatus
                            ? $m->relatedRequest->status
                            : \App\Enums\RequestStatus::tryFrom((string) $m->relatedRequest->status);
                    }
                    $awaitingInv = $rrs === \App\Enums\RequestStatus::AwaitingInvoice;
                    [$partyName, $partyEmail, $isToParty, $moreTo] = $counterparty($m);
                    // В «Отправленных»/«Черновиках» папка и так говорит, что это
                    // адресат; в смешанных папках без подписи не разобрать.
                    $toPrefix = $isToParty && ! in_array($folder, ['sent', 'drafts'], true);
                @endphp
                @php $rowLabelIds = $m->labels->pluck('id')->all(); @endphp
                <div class="trow {{ $unread ? 'unread' : '' }} {{ $openId === $m->id ? 'active' : '' }} {{ $awaitingInv ? 'awaiting-inv' : '' }}"
                     wire:key="trow-{{ $m->id }}" wire:click="openMessage({{ $m->id }})"
                     data-id="{{ $m->id }}" :class="{ sel: has({{ $m->id }}) }"
                     draggable="true" @dragstart="dragStart({{ $m->id }}, $event)"
                     @contextmenu.prevent.stop="openMenu({{ $m->id }}, {{ \Illuminate\Support\Js::from($rowLabelIds) }}, $event)">
                    @if($unread)<span class="dot-unread"></span>@endif
                    <span class="chk" @click.stop="toggle({{ $m->id }}, $event)" title="Выбрать (Shift — диапазон, Ctrl+A — все)"></span>
                    <div class="avcol">
                        <span class="av {{ $isOrg ? 'org' : '' }}">{{ $initials($partyName, $partyEmail) }}</span>
                        <button type="button" class="flagbtn {{ $m->my_flagged_at ? 'on' : '' }}"
                                wire:click.stop="toggleFlag({{ $m->id }})"
                                title="{{ $m->my_flagged_at ? 'Снять пометку' : 'Пометить' }}">⚑</button>
                    </div>
                    <div class="body">
                        <div class="l1">
                            <span class="from" title="{{ $isToParty ? 'Кому: ' : '' }}{{ $partyEmail }}">{{ $toPrefix ? 'Кому: ' : '' }}{{ $partyName ?: $partyEmail }}@if($moreTo) <span class="more-to">+{{ $moreTo }}</span>@endif</span>
                            <span class="when">{{ $fmtWhen($m->sent_at) }}</span>
                        </div>
                        <div class="l2"><span class="subj">{{ $m->subject ?: '(без темы)' }}</span></div>
                        @if($m->labels->isNotEmpty())
                            <div class="rowlabels">
                                @foreach($m->labels as $label)
                                    <span class="rowlabel" wire:key="rl-{{ $m->id }}-{{ $label->id }}"
                                          style="background:{{ $label->bg() }};color:{{ $label->fg() }}">{{ $label->name }}</span>
                                @endforeach
                            </div>
                        @endif
                        <div class="l3">
                            <span class="snip">{{ \Illuminate\Support\Str::limit(trim((string) $m->body_plain), 90) }}</span>
                            <span class="metaicons">
                                @if($m->attachments_count)<span class="clip">📎</span>@endif
                                @if($m->mailbox_folder_id && $m->mailbox_folder_id !== $this->customFolderId() && isset($this->folderNames[$m->mailbox_folder_id]))
                                    <span class="fchip" title="Письмо лежит в папке">▸ {{ $this->folderNames[$m->mailbox_folder_id] }}</span>
                                @endif
                                @if($m->related_request_id && $m->relatedRequest)
                                    @if($awaitingInv)<span class="rubchip" title="Клиент ждёт счёт — счёт ещё не выставлен">₽</span>@endif
                                    {{-- Система уже посчитала КП по этой заявке: открыть и отправить. --}}
                                    @if(isset($autoQuotes[$m->related_request_id]))
                                        <span class="aqchip"
                                              title="Система подготовила КП на {{ number_format((float) $autoQuotes[$m->related_request_id]->total, 2, ',', ' ') }} ₽ — проверить и отправить в карточке заявки">КП готово</span>
                                    @endif
                                    <span class="reqchip">{{ $m->relatedRequest->internal_code }}</span>
                                    @if($m->relatedRequest->onec_number)<span class="onecchip" title="Номер заявки/КП в 1С">1С {{ $m->relatedRequest->onec_number }}</span>@endif
                                @elseif($cat)
                                    <span class="catchip {{ $cat[1] }}">{{ $cat[0] }}</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="empty">
                    @if($unreadOnly)
                        Непрочитанных писем в папке «{{ $this->currentFolderLabel }}» нет.
                        <button type="button" class="linkbtn" wire:click="toggleUnreadOnly">Показать все</button>
                    @else
                        В папке «{{ $this->currentFolderLabel }}» пока пусто.
                    @endif
                </div>
            @endforelse

            @if($this->hasMore)
                <div class="blist-foot"><button wire:click="loadMore">Показать ещё</button></div>
            @endif
        </div>

        {{-- Контекстное меню строки: метки (поставить/снять), создание метки
             на лету и быстрые действия. Открывается правым кликом по письму. --}}
        <div class="ctxmenu" x-show="menu.open" x-cloak
             :style="`left:${menu.x}px; top:${menu.y}px`"
             @click.outside="closeMenu()" @contextmenu.outside="closeMenu()">
            <div class="sec" x-text="(sel.length > 1 && has(menu.id)) ? ('Метки · выбрано ' + sel.length) : 'Метки'"></div>
            @forelse($this->labels as $label)
                <button type="button" wire:key="cm-{{ $label->id }}" @click="toggleLabel({{ $label->id }})">
                    <span class="tick" x-text="hasLabel({{ $label->id }}) ? '✓' : ''"></span>
                    <span class="swatch" style="background:{{ $label->fg() }}"></span>
                    <span>{{ $label->name }}</span>
                </button>
            @empty
                <div class="sec" style="text-transform:none;letter-spacing:0;font-weight:400">Меток пока нет — заведите первую ниже.</div>
            @endforelse

            <div class="newlbl">
                <input type="text" x-model="newLabel" maxlength="{{ \App\Models\MailLabel::NAME_MAX }}"
                       placeholder="Новая метка" @keydown.enter.prevent="addLabel()" @keydown.stop>
                <button type="button" @click="addLabel()">+</button>
            </div>

            <div class="sep"></div>
            <button type="button" @click="$wire.markManyRead(targets()); closeMenu()">Прочитано</button>
            <button type="button" @click="$wire.markManyUnread(targets()); closeMenu()">Непрочитано</button>
            <button type="button" @click="$wire.toggleFlag(menu.id); closeMenu()">⚑ Пометить</button>
            <div class="sep"></div>
            <div class="sec" style="text-transform:none;letter-spacing:0;font-weight:400">
                Метки личные — у каждого свой набор.
            </div>
        </div>

    </div>

    {{-- Рукоятка ширины списка. Состояние держит она сама, а не корневой x-data:
         события мыши приходят на элемент внутри своей области видимости, и
         обмен состоянием между вложенными x-data — лишний источник поломок.
         Ширина пишется в --paneB-w на <html> (wire:poll морфит компонент, и
         инлайновый стиль на нём слетал бы) и дублируется в localStorage.
         На время перетаскивания гасим pointer-events у iframe письма: иначе
         курсор заходит на тело письма, iframe съедает mousemove и тяга встаёт. --}}
    <div class="bresize"
         x-data="{
            min: 300, max: 820, drag: false, ox: 0, ow: 400,
            current() {
                const v = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--paneB-w'), 10);
                return Number.isFinite(v) ? v : 400;
            },
            setW(px) {
                const v = Math.max(this.min, Math.min(this.max, Math.round(px)));
                document.documentElement.style.setProperty('--paneB-w', v + 'px');
                try { localStorage.setItem('mzcorp.mail.listWidth', String(v)); } catch (e) {}
            },
            start(ev) {
                this.drag = true;
                this.ox = ev.clientX;
                this.ow = this.current();
                document.body.classList.add('mail-resizing');
            },
            move(ev) { if (this.drag) this.setW(this.ow + (ev.clientX - this.ox)); },
            stop() {
                if (! this.drag) return;
                this.drag = false;
                document.body.classList.remove('mail-resizing');
            }
         }"
         x-init="(() => {
            const saved = parseInt(localStorage.getItem('mzcorp.mail.listWidth') || '', 10);
            if (Number.isFinite(saved)) { document.documentElement.style.setProperty('--paneB-w', Math.max(300, Math.min(820, saved)) + 'px'); }
         })()"
         :class="{ dragging: drag }"
         @mousedown.prevent="start($event)"
         @mousemove.window="move($event)"
         @mouseup.window="stop()"
         @dblclick="setW(400)"
         title="Потяните, чтобы изменить ширину списка. Двойной клик — вернуть по умолчанию"></div>

    {{-- ══════════ PANE C — чтение ══════════ --}}
    <div class="paneC">
        @php $anchor = $this->openAnchor; @endphp
        @if(! $anchor)
            <div class="empty-read">
                <div class="big">✉</div>
                <div>Выберите письмо, чтобы прочитать</div>
            </div>
        @else
            @php $thread = $this->openThread; $req = $anchor->relatedRequest; @endphp
            <div class="chead">
                <div class="top1">
                    <h1>{{ $anchor->subject ?: '(без темы)' }}</h1>
                    <button class="menu" wire:click="markUnread({{ $anchor->id }})" title="Пометить непрочитанным">⋯</button>
                </div>
                <div class="meta">
                    @php [$headName, $headEmail, $headIsTo] = $counterparty($anchor); @endphp
                    <span>{{ $headIsTo ? 'кому: ' : '' }}{{ $headName ?: $headEmail }} · {{ $thread->count() }} писем</span>
                    @if($thread->count() > 1)
                        {{-- Порядок писем — та же персональная настройка, что в «Переписке» карточки заявки. --}}
                        <button type="button" class="sortbtn" wire:click="toggleThreadSort"
                                title="Порядок писем в переписке — переключить (сохраняется в ваших настройках, действует и в карточке заявки)">
                            {{ $threadSort === 'desc' ? 'Сначала новые ↓' : 'Сначала старые ↑' }}
                        </button>
                    @endif
                </div>
                @if($req)
                    <div class="reqlink">
                        <span>Привязано к заявке</span>
                        <span class="code">{{ $req->internal_code }}</span>
                        @if($req->onec_number)<span class="onec" title="Номер заявки/КП в 1С">1С: {{ $req->onec_number }}</span>@endif
                        @php
                            $reqStatus = $req->status instanceof \App\Enums\RequestStatus
                                ? $req->status
                                : \App\Enums\RequestStatus::tryFrom((string) $req->status);
                        @endphp
                        <span class="st">· {{ $reqStatus?->label() ?? $req->status }}</span>
                        <span class="spacer"></span>
                        <a href="{{ route('requests.show', $req->id) }}" wire:navigate>Открыть заявку →</a>
                    </div>
                @elseif($anchor->direction?->value === 'inbound' && $this->canPromote)
                    {{-- Письмо не стало заявкой: постпродажа, «не заявка», спорный
                         разбор. Менеджер видит письмо целиком и решает лучше
                         автомата — даём ему сказать это одной кнопкой, как в
                         разделе «Авто-отклонённые». --}}
                    <div class="reqlink promote">
                        <span>Заявки по письму нет</span>
                        <span class="spacer"></span>
                        <button type="button" class="btn btn-sm btn-primary"
                                wire:click="promoteToRequest({{ $anchor->id }})"
                                wire:loading.attr="disabled" wire:target="promoteToRequest"
                                wire:confirm="Создать заявку из этого письма? Запустится разбор позиций и назначение менеджера.">
                            Это заявка!
                        </button>
                    </div>
                @endif
                {{-- Журнал решений маршрутизатора по этому письму (mail_decisions): почему оно ушло туда, куда ушло. --}}
                @php $decisions = $anchor->direction?->value === 'inbound' ? $anchor->decisions()->limit(3)->get() : collect(); @endphp
                @if($decisions->isNotEmpty())
                    <div class="decisions" title="Решения маршрутизатора по письму, новые первыми">
                        @foreach($decisions as $d)
                            <span class="dec {{ $d->outcome }}">
                                <b>{{ \App\Services\Mail\MailDecisionRecorder::label($d->stage) }}</b>
                                @if($d->request && $d->request_id !== ($req?->id))<span class="mono">{{ $d->request->internal_code }}</span>@endif
                                <span class="when">{{ $d->created_at?->timezone(config('app.timezone'))->format('d.m H:i') }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Остальные письма переписки — свёрнутым списком. Показываем ровно
                 то письмо, которое выбрали в списке (как в обычном почтовике);
                 к соседним можно перейти отсюда одним кликом. --}}
            @php $others = $thread->reject(fn ($m) => (int) $m->id === (int) $anchor->id); @endphp
            @if($others->isNotEmpty())
                <div class="tstrip" x-data="{ open: false }">
                    <button type="button" class="tstrip-head" @click="open = ! open">
                        <span x-text="open ? '▾' : '▸'"></span>
                        <span>Ещё {{ $others->count() }} {{ $plural($others->count()) }} в этой переписке</span>
                    </button>
                    <div class="tstrip-list" x-show="open" x-cloak>
                        @foreach(($threadSort === 'desc' ? $thread->reverse() : $thread) as $m)
                            @php [$sName, $sEmail, $sIsTo] = $counterparty($m); @endphp
                            <button type="button" wire:key="ts-{{ $m->id }}"
                                    class="tstrip-item {{ (int) $m->id === (int) $anchor->id ? 'cur' : '' }}"
                                    wire:click="openMessage({{ $m->id }})">
                                <span class="dir">{{ $m->direction?->value === 'outbound' ? '↑' : '↓' }}</span>
                                <span class="who">{{ $sIsTo ? 'кому: ' : '' }}{{ \Illuminate\Support\Str::limit($sName ?: $sEmail, 28) }}</span>
                                <span class="sub">{{ \Illuminate\Support\Str::limit($m->subject ?: '(без темы)', 42) }}</span>
                                @if($m->attachments->isNotEmpty())<span class="clip">📎</span>@endif
                                <span class="when">{{ $fmtWhen($m->is_draft ? ($m->last_edited_at ?? $m->created_at) : $m->sent_at) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="cbody">
                @php $openMsg = $thread->firstWhere('id', $anchor->id) ?? $anchor; @endphp
                @foreach([$openMsg] as $msg)
                    @php $outbound = $msg->direction?->value === 'outbound'; $html = $this->bodyHtmlFor($msg); @endphp
                    <div class="msg {{ $msg->is_draft ? 'draft' : ($outbound ? 'outbound' : '') }}" wire:key="msg-{{ $msg->id }}">
                        <div class="mhead">
                            <span class="av">{{ $msg->is_draft ? '✎' : $initials($msg->from_name, $msg->from_email) }}</span>
                            <div class="who">
                                <div class="nm">{{ $msg->from_name ?: $msg->from_email }} <span class="em">&lt;{{ $msg->from_email }}&gt;</span>@if($msg->is_draft)<span class="draft-badge">черновик</span>@endif</div>
                                @php $to = collect($msg->to_recipients ?? [])->pluck('email')->filter()->take(3)->implode(', '); @endphp
                                @if($to)<div class="tocc">кому: {{ $to }}</div>@endif
                            </div>
                            @unless($msg->is_draft)
                                <span class="msg-acts">
                                    <button wire:click="reply({{ $msg->id }})" title="Ответить на это письмо">Ответить</button>
                                    <button wire:click="forward({{ $msg->id }})" title="Переслать это письмо">Переслать</button>
                                </span>
                            @endunless
                            <span class="when">{{ $fmtWhen($msg->is_draft ? ($msg->last_edited_at ?? $msg->created_at) : $msg->sent_at) }}</span>
                        </div>
                        <div class="mbody">
                            @if($html)
                                {{-- Два грабля, оба лечим тут:
                                     1) loading="lazy" НЕЛЬЗЯ: стартовая height:0 = нулевая площадь,
                                        браузер не считает iframe видимым и не грузит srcdoc → load не
                                        стреляет, письмо пустое (тело появлялось только при ресайзе окна).
                                     2) У свежего iframe СНАЧАЛА лежит пустой about:blank-документ, и у
                                        него уже есть body. Настраиваться по нему нельзя: когда приедет
                                        srcdoc, документ подменится, ResizeObserver останется на
                                        выброшенном documentElement, а высота застрянет на ~12px (письмо
                                        полоской со скроллом). Поэтому about:blank пропускаем, а «уже
                                        настроено» помечаем ссылкой на сам документ (_mlDoc), не флагом —
                                        новый документ настраивается заново.
                                     wire:ignore.self — чтобы морф Livewire (отметка прочитанным, флаг,
                                     события композера) не сбрасывал inline-высоту обратно в 0. --}}
                                <iframe wire:ignore.self
                                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                        srcdoc="{{ $html }}" style="height:0"
                                        x-data x-init="
                                            const fit=()=>{try{const d=$el.contentDocument;if(!d||!d.documentElement)return;
                                                $el.style.height='8px';$el.style.height=(d.documentElement.scrollHeight+4)+'px'}catch(e){}};
                                            const boot=()=>{try{const d=$el.contentDocument;
                                                if(!d||!d.body||(d.URL||'')==='about:blank')return false;
                                                if($el._mlDoc===d)return true;$el._mlDoc=d;
                                                d.querySelectorAll('a[href]').forEach(a=>{a.target='_blank';a.rel='noopener noreferrer'});
                                                const s=d.createElement('style');s.textContent='html,body{margin:0;padding:0}body{padding:6px 8px;font:13px/1.55 system-ui,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}img[data-cid-missing]{width:14px!important;height:14px!important;opacity:.35}';
                                                (d.head||d.documentElement).appendChild(s);
                                                try{new ResizeObserver(fit).observe(d.documentElement)}catch(e){}
                                                d.addEventListener('toggle',fit,true);fit();
                                                setTimeout(fit,200);return true}catch(e){return false}};
                                            $el.addEventListener('load',boot);
                                            boot();requestAnimationFrame(boot);setTimeout(boot,300);setTimeout(boot,1000)
                                        "></iframe>
                            @elseif($msg->body_plain)
                                <pre>{{ $msg->body_plain }}</pre>
                            @else
                                <div style="color:var(--fg-3);font-size:12.5px">(пустое тело)</div>
                            @endif
                        </div>
                        @if($msg->attachments->isNotEmpty())
                            @php
                                $photos = $msg->attachments->filter(fn($a) => str_starts_with((string) $a->mime_type, 'image/'));
                                $files  = $msg->attachments->reject(fn($a) => str_starts_with((string) $a->mime_type, 'image/'));
                            @endphp
                            @if($photos->isNotEmpty())
                                @php
                                    $photoItems = $photos->values()->map(fn ($a) => [
                                        'src' => route('attachments.preview', $a->id),
                                        'name' => $a->display_filename,
                                        'dl' => route('attachments.download', $a->id),
                                    ]);
                                @endphp
                                <div class="photos">
                                    @foreach($photos->values() as $i => $att)
                                        <a class="photo" href="{{ route('attachments.preview', $att->id) }}"
                                           @click.prevent="$dispatch('open-image', { items: {{ \Illuminate\Support\Js::from($photoItems) }}, index: {{ $i }} })"
                                           title="{{ $att->display_filename }}">
                                            <img src="{{ route('attachments.preview', $att->id) }}" loading="lazy" alt="{{ $att->display_filename }}">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                            @if($files->isNotEmpty())
                                <div class="attachments">
                                    @foreach($files as $att)
                                        @php $ext = strtoupper(pathinfo($att->display_filename, PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                                        <a class="att" href="{{ route('attachments.preview', $att->id) }}" target="_blank" rel="noopener" title="Открыть / скачать">
                                            <span class="ico">{{ mb_substr($ext, 0, 4) }}</span>
                                            <span>
                                                <span class="fn" style="display:block">{{ $att->display_filename }}</span>
                                                <span class="sz">{{ $att->size_bytes ? number_format($att->size_bytes/1024, 0, '.', ' ').' КБ' : '' }}</span>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @if($msg->is_draft)
                            <div class="draft-actions">
                                <button class="da-primary" wire:click="continueDraft({{ $msg->id }})">Продолжить черновик</button>
                                <button class="da-del" wire:click="deleteDraft({{ $msg->id }})">Удалить</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Отвечаем на ОТКРЫТОЕ письмо, а не на последнее в переписке:
                 на экране именно оно, и ответ должен уйти в его тред. --}}
            @php $replyTo = $openMsg->is_draft ? ($thread->reject(fn ($m) => $m->is_draft)->last() ?? $anchor) : $openMsg; @endphp
            <div class="cfoot">
                <div class="replybtns">
                    <button class="primary" wire:click="reply({{ $replyTo->id }})">Ответить</button>
                    <button wire:click="replyAll({{ $replyTo->id }})">Ответить всем</button>
                </div>
                <div class="cfoot-hint">Переслать конкретное письмо — кнопкой у самого письма выше</div>
            </div>
        @endif
    </div>

    {{-- Плавающий композер (Фаза 2) — открывается событиями mail-open-* --}}
    <livewire:mail.composer />

    {{-- Просмотрщик фото (тот же, что в заявках) — событие open-image. --}}
    @include('partials.image-lightbox')
</div>
