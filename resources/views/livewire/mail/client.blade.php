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
    $catChip = function ($cat) {
        return match ($cat) {
            'client_request' => ['заявка', 'kp'],
            'supplier_reply' => ['поставщик', 'clar'],
            'post_sale'      => ['пост-продажа', 'invoice'],
            default          => null,
        };
    };
@endphp

<div class="mailapp" wire:key="mailapp">
<style>
/* scoped mail client — на токенах дизайн-системы, без Tailwind-пересборки */
.mailapp{display:grid;grid-template-columns:240px 400px 1fr;height:calc(100vh - var(--topbar-h, 56px));min-height:520px;
    overflow:hidden;background:var(--bg-surface);font-family:var(--font-sans);color:var(--fg-1)}
.mailapp *{box-sizing:border-box}
@media(max-width:1100px){.mailapp{grid-template-columns:220px 1fr}.mailapp .paneC{display:none}}

/* PANE A */
.mailapp .paneA{background:var(--bg-sidebar);border-right:1px solid var(--border);overflow-y:auto;display:flex;flex-direction:column}
.mailapp .mbx-switch{padding:10px;border-bottom:1px solid var(--border-subtle)}
.mailapp .cur{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--r-md);background:var(--bg-surface)}
.mailapp .cur .dot{width:7px;height:7px;border-radius:999px;background:var(--emerald-600);flex-shrink:0}
.mailapp .cur .dot.err{background:var(--amber-600)}
.mailapp .cur .txt{flex:1;min-width:0}
.mailapp .cur .nm{font:600 12.5px/1.3 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mailapp .cur .em{font:400 11px/1.2 var(--font-mono);color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
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
.mailapp .trow{display:grid;grid-template-columns:30px 1fr;column-gap:10px;padding:10px 12px;border-bottom:1px solid var(--border-subtle);cursor:pointer;position:relative}
.mailapp .trow:hover{background:var(--bg-hover)}
.mailapp .trow.active{background:var(--bg-selected);box-shadow:inset 3px 0 0 var(--sky-500)}
.mailapp .trow .dot-unread{width:7px;height:7px;border-radius:999px;background:var(--accent);position:absolute;left:4px;top:18px}
.mailapp .trow .av{width:30px;height:30px;border-radius:999px;background:var(--neutral-200);color:var(--fg-2);font:600 12px/30px var(--font-sans);text-align:center;flex-shrink:0}
.mailapp .trow .av.org{background:var(--sky-50);color:var(--sky-700)}
.mailapp .trow .body{min-width:0}
.mailapp .trow .l1{display:flex;align-items:baseline;gap:6px}
.mailapp .trow .from{font:500 13px/1.3 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mailapp .trow.unread .from{font-weight:700}
.mailapp .trow .when{font:500 11px/1 var(--font-mono);color:var(--fg-3);flex-shrink:0}
.mailapp .trow.unread .when{color:var(--fg-1);font-weight:600}
.mailapp .trow .l2{display:flex;align-items:baseline;gap:6px;margin-top:2px}
.mailapp .trow .subj{font:500 12.5px/1.35 var(--font-sans);color:var(--fg-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mailapp .trow.unread .subj{font-weight:600}
.mailapp .trow .l3{display:flex;align-items:center;gap:6px;margin-top:3px}
.mailapp .trow .snip{font:400 12px/1.3 var(--font-sans);color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mailapp .trow .metaicons{display:flex;align-items:center;gap:6px;flex-shrink:0}
.mailapp .trow .flagbtn{border:none;background:none;cursor:pointer;font-size:12px;color:var(--fg-4);padding:0;line-height:1}
.mailapp .trow .flagbtn.on{color:var(--amber-600)}
.mailapp .trow .clip{color:var(--fg-3);font-size:12px}
.mailapp .trow .reqchip{font:600 10.5px/1.4 var(--font-mono);background:var(--violet-50);color:var(--violet-700);padding:1px 6px;border-radius:4px}
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
.mailapp .chead .meta{font:400 12px/1.4 var(--font-sans);color:var(--fg-3);margin-top:5px}
.mailapp .chead .reqlink{display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:8px 12px;background:var(--violet-50);border:1px solid var(--violet-600);border-radius:var(--r-md);font-size:12.5px}
.mailapp .chead .reqlink .code{font-family:var(--font-mono);font-weight:600;color:var(--violet-700)}
.mailapp .chead .reqlink .st{color:var(--violet-700)}
.mailapp .chead .reqlink .spacer{flex:1}
.mailapp .chead .reqlink a{color:var(--violet-700);font-weight:600;text-decoration:none;border-bottom:1px dashed currentColor}
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
    <div class="paneA">
        @php $groups = $this->mailboxGroups; $cur = $groups['current']; @endphp
        <div class="mbx-switch">
            <div class="cur">
                <span class="dot {{ ($cur['error'] ?? false) ? 'err' : '' }}"></span>
                <div class="txt">
                    <div class="nm">{{ $cur['name'] ?? 'Ящик' }}@if(($cur['kind'] ?? '')==='shared') · общий @elseif(($cur['kind'] ?? '')==='delegated') · делег. @endif</div>
                    <div class="em">{{ $cur['email'] ?? '' }}</div>
                </div>
            </div>
        </div>

        <div class="flist">
            @foreach($this->folders as $f)
                <button type="button" wire:key="fld-{{ $f['key'] }}"
                        wire:click="selectFolder('{{ $f['key'] }}')"
                        class="fitem {{ $f['active'] ? 'active' : '' }}">
                    <span class="lbl">{{ $f['label'] }}</span>
                    @if($f['count'])
                        @if($f['unread'])<span class="pill">{{ $f['count'] }}</span>@else<span class="n">{{ $f['count'] }}</span>@endif
                    @endif
                </button>
            @endforeach

            @if(!empty($groups['shared']) || !empty($groups['delegated']) || !empty($groups['personalOthers']))
                <div class="fgroup-label">Другие ящики</div>
                @foreach(array_merge($groups['personalOthers'], $groups['shared']) as $mb)
                    <button type="button" wire:key="mbx-{{ $mb['id'] }}" wire:click="selectMailbox({{ $mb['id'] }})" class="fitem">
                        <span class="lbl">{{ $mb['kind']==='shared' ? 'Общий · '.$mb['email'] : $mb['name'] }}</span>
                        @if($mb['unread'])<span class="pill">{{ $mb['unread'] }}</span>@endif
                    </button>
                @endforeach
                @foreach($groups['delegated'] as $mb)
                    <button type="button" wire:key="mbx-{{ $mb['id'] }}" wire:click="selectMailbox({{ $mb['id'] }})" class="fitem {{ $mb['error'] ? 'err' : '' }}">
                        <span class="lbl">{{ $mb['name'] }} (делег.)</span>
                        @if($mb['unread'])<span class="pill">{{ $mb['unread'] }}</span>@endif
                    </button>
                @endforeach
            @endif
        </div>
    </div>

    {{-- ══════════ PANE B — список тредов ══════════ --}}
    <div class="paneB">
        <div class="blist-top">
            <div class="row1">
                <div class="bsearch">
                    <input type="text" placeholder="Поиск в этом ящике…" wire:model.live.debounce.400ms="search">
                </div>
                <button class="compose" wire:click="$dispatch('mail-open-compose', { mailboxId: {{ (int) $selectedMailboxId }} })">Написать</button>
            </div>
            <div class="fhdr">
                <span>{{ \App\Enums\MailFolder::tryFromOrDefault($folder)->label() }}</span>
                <span><b>{{ number_format($this->totalCount, 0, '.', ' ') }}</b> писем</span>
            </div>
        </div>

        <div class="threads">
            @forelse($this->threads->take($perPage) as $m)
                @php
                    $unread = $m->my_read_at === null && $m->direction?->value === 'inbound';
                    $cat = $catChip($m->category);
                    $isOrg = (bool) $m->related_request_id;
                @endphp
                <div class="trow {{ $unread ? 'unread' : '' }} {{ $openId === $m->id ? 'active' : '' }}"
                     wire:key="trow-{{ $m->id }}" wire:click="openMessage({{ $m->id }})">
                    @if($unread)<span class="dot-unread"></span>@endif
                    <span class="av {{ $isOrg ? 'org' : '' }}">{{ $initials($m->from_name, $m->from_email) }}</span>
                    <div class="body">
                        <div class="l1">
                            <span class="from">{{ $m->from_name ?: $m->from_email }}</span>
                            <span class="when">{{ $fmtWhen($m->sent_at) }}</span>
                        </div>
                        <div class="l2"><span class="subj">{{ $m->subject ?: '(без темы)' }}</span></div>
                        <div class="l3">
                            <span class="snip">{{ \Illuminate\Support\Str::limit(trim((string) $m->body_plain), 90) }}</span>
                            <span class="metaicons">
                                <button type="button" class="flagbtn {{ $m->my_flagged_at ? 'on' : '' }}"
                                        wire:click.stop="toggleFlag({{ $m->id }})" title="Пометить">⚑</button>
                                @if($m->attachments_count)<span class="clip">📎</span>@endif
                                @if($m->related_request_id && $m->relatedRequest)
                                    <span class="reqchip">{{ $m->relatedRequest->internal_code }}</span>
                                @elseif($cat)
                                    <span class="catchip {{ $cat[1] }}">{{ $cat[0] }}</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="empty">В папке «{{ \App\Enums\MailFolder::tryFromOrDefault($folder)->label() }}» пока пусто.</div>
            @endforelse

            @if($this->hasMore)
                <div class="blist-foot"><button wire:click="loadMore">Показать ещё</button></div>
            @endif
        </div>
    </div>

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
                <div class="meta">{{ $anchor->from_name ?: $anchor->from_email }} · {{ $thread->count() }} писем</div>
                @if($req)
                    <div class="reqlink">
                        <span>Привязано к заявке</span>
                        <span class="code">{{ $req->internal_code }}</span>
                        @php
                            $reqStatus = $req->status instanceof \App\Enums\RequestStatus
                                ? $req->status
                                : \App\Enums\RequestStatus::tryFrom((string) $req->status);
                        @endphp
                        <span class="st">· {{ $reqStatus?->label() ?? $req->status }}</span>
                        <span class="spacer"></span>
                        <a href="{{ route('requests.show', $req->id) }}" wire:navigate>Открыть заявку →</a>
                    </div>
                @endif
            </div>

            <div class="cbody">
                @foreach($thread as $msg)
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
                                    <button wire:click="$dispatch('mail-open-reply', { messageId: {{ $msg->id }} })" title="Ответить на это письмо">Ответить</button>
                                    <button wire:click="$dispatch('mail-open-forward', { messageId: {{ $msg->id }} })" title="Переслать это письмо">Переслать</button>
                                </span>
                            @endunless
                            <span class="when">{{ $fmtWhen($msg->is_draft ? ($msg->last_edited_at ?? $msg->created_at) : $msg->sent_at) }}</span>
                        </div>
                        <div class="mbody">
                            @if($html)
                                <iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                        srcdoc="{{ $html }}" loading="lazy" style="height:0"
                                        x-data x-init="
                                            const fit=()=>{try{const h=$el.contentDocument?.documentElement?.scrollHeight||0;$el.style.height=(h+4)+'px'}catch(e){}};
                                            $el.addEventListener('load',()=>{try{const d=$el.contentDocument;if(!d)return;
                                                d.querySelectorAll('a[href]').forEach(a=>{a.target='_blank';a.rel='noopener noreferrer'});
                                                const s=d.createElement('style');s.textContent='html,body{margin:0;padding:0}body{padding:6px 8px;font:13px/1.55 system-ui,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}';
                                                (d.head||d.documentElement).appendChild(s);try{new ResizeObserver(fit).observe(d.documentElement)}catch(e){}fit()}catch(e){}})
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
                                        'name' => $a->filename,
                                        'dl' => route('attachments.download', $a->id),
                                    ]);
                                @endphp
                                <div class="photos">
                                    @foreach($photos->values() as $i => $att)
                                        <a class="photo" href="{{ route('attachments.preview', $att->id) }}"
                                           @click.prevent="$dispatch('open-image', { items: {{ \Illuminate\Support\Js::from($photoItems) }}, index: {{ $i }} })"
                                           title="{{ $att->filename }}">
                                            <img src="{{ route('attachments.preview', $att->id) }}" loading="lazy" alt="{{ $att->filename }}">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                            @if($files->isNotEmpty())
                                <div class="attachments">
                                    @foreach($files as $att)
                                        @php $ext = strtoupper(pathinfo($att->filename, PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                                        <a class="att" href="{{ route('attachments.preview', $att->id) }}" target="_blank" rel="noopener" title="Открыть / скачать">
                                            <span class="ico">{{ mb_substr($ext, 0, 4) }}</span>
                                            <span>
                                                <span class="fn" style="display:block">{{ $att->filename }}</span>
                                                <span class="sz">{{ $att->size_bytes ? number_format($att->size_bytes/1024, 0, '.', ' ').' КБ' : '' }}</span>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @if($msg->is_draft)
                            <div class="draft-actions">
                                <button class="da-primary" wire:click="$dispatch('mail-open-draft', { draftId: {{ $msg->id }} })">Продолжить черновик</button>
                                <button class="da-del" wire:click="deleteDraft({{ $msg->id }})">Удалить</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @php $lastMsg = $thread->reject(fn ($m) => $m->is_draft)->last() ?? $anchor; @endphp
            <div class="cfoot">
                <div class="replybtns">
                    <button class="primary" wire:click="$dispatch('mail-open-reply', { messageId: {{ $lastMsg->id }} })">Ответить</button>
                    <button wire:click="$dispatch('mail-open-reply-all', { messageId: {{ $lastMsg->id }} })">Ответить всем</button>
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
