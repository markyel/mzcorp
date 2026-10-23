{{-- Вкладка «Поставщики» заявки, верх (дизайн «Request — Поставщики», 10.2026): сводка, вопросы поставщиков,
     сравнение цен по позициям, поставщики и переписка. Письма рассылки и QuoteParts — вместе. Данные —
     RequestSupplierOverview; стили — здесь же, на токенах дизайн-системы (новые классы Tailwind без пересборки CSS не работают). --}}
@php
    $ov = $this->overview;
    $cmp = $ov->comparison();
    $sups = collect($ov->suppliers($this->supplierCorrespondence))->sortByDesc('sort')->values();
    $qs = collect($ov->questions());
    $sum = $ov->summary($sups->all());
    $usd = \App\Services\Iqot\IqotCurrencyConverter::rateToRub('USD');
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $rubf = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $canPick = $this->canDispatch;
    $canAnswer = $this->canAnswerQuoteParts();
    $kinds = ['original' => ['оригинал', 'ok'], 'copy' => ['копия', 'attn'], 'analog' => ['аналог', 'attn'], 'refurbished' => ['восстановленное', 'attn'], 'replacement' => ['замена', 'attn'], 'unknown' => ['тип не указан', 'neutral']];
    $statusChip = ['priced' => ['дал цену', 'ok'], 'priced_question' => ['дал цену · есть вопрос', 'attn'], 'question' => ['есть вопрос', 'attn'],
        'no_price' => ['ответил без цены', 'attn'], 'refused' => ['отказ', 'over'], 'waiting' => ['ждём ответа', 'neutral'], 'loose' => ['копии на rfq@', 'neutral']];
    $qpUrl = rtrim((string) config('services.quoteparts.url'), '/');
@endphp
<style>
    .rs{display:flex;flex-direction:column;gap:16px;font-size:13px;color:var(--fg-1)}
    .rs .tnum{font-feature-settings:'tnum'}
    .rs-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
    .rs-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 20px;border-bottom:1px solid var(--border)}
    .rs-head h2{margin:0;font-size:15px;font-weight:600}
    .rs-hint{font-size:12px;color:var(--fg-3)}
    .rs-pill{padding:1px 8px;border-radius:999px;font-size:12px;font-weight:500;white-space:nowrap}
    .rs-pill.ok{background:var(--status-ok-bg);color:var(--status-ok-fg)}
    .rs-pill.attn{background:var(--status-attn-bg);color:var(--status-attn-fg)}
    .rs-pill.over{background:var(--status-overdue-bg);color:var(--status-overdue-fg)}
    .rs-pill.neutral{background:var(--status-neutral-bg);color:var(--status-neutral-fg)}
    .rs-pill.info{background:var(--status-info-bg);color:var(--status-info-fg)}
    .rs-ch{padding:0 6px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap}
    .rs-ch.mail{background:var(--status-neutral-bg);color:var(--status-neutral-fg)}
    .rs-ch.quoteparts{background:var(--sky-50);color:var(--sky-700)}
    .rs-stats{display:flex;align-items:center;gap:28px;flex-wrap:wrap;padding:14px 20px}
    .rs-stat{display:flex;flex-direction:column;gap:2px}
    .rs-stat .l{font-size:12px;color:var(--fg-3)}
    .rs-stat .v{font-size:16px;font-weight:600;font-feature-settings:'tnum'}
    .rs-stat .v small{font-size:13px;font-weight:400;color:var(--fg-3)}
    .rs-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:28px;padding:0 10px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;border:1px solid var(--border-strong);background:var(--bg-surface);color:var(--fg-1);transition:background 120ms}
    .rs-btn:hover{background:var(--bg-hover)}
    .rs-btn.ghost{border-color:transparent;background:transparent;color:var(--fg-2)}
    .rs-btn.ghost:hover{background:var(--bg-hover);color:var(--fg-1)}
    .rs-btn.accent{border:0;background:var(--accent);color:#fff;font-weight:600;height:30px;padding:0 12px}
    .rs-btn.accent:hover{background:var(--accent-600)}
    .rs-btn.picked{border:0;background:var(--sky-600);color:#fff;font-weight:600}
    .rs-btn[disabled]{opacity:.5;cursor:default}
    .rs-grid{display:grid;grid-template-columns:minmax(160px,240px) 150px minmax(0,1fr) 150px;gap:12px}
    .rs-colhead{align-items:center;height:32px;padding:0 16px 0 20px;border-bottom:1px solid var(--border-strong);font-size:12px;font-weight:500;color:var(--fg-3)}
    .rs-pos{border-bottom:1px solid var(--border-strong);box-shadow:inset 4px 0 0 var(--rs-bar)}
    .rs-pos.priced{--rs-bar:var(--emerald-600);--rs-head:var(--emerald-50);--rs-head-bd:oklch(88% 0.06 155);--rs-ans-bg:var(--emerald-600);--rs-ans-fg:#fff}
    .rs-pos.waiting{--rs-bar:var(--neutral-400);--rs-head:var(--neutral-100);--rs-head-bd:var(--border);--rs-ans-bg:var(--bg-surface);--rs-ans-fg:var(--fg-2)}
    .rs-pos-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px 12px 20px;background:var(--rs-head);border-bottom:1px solid var(--rs-head-bd);font-size:12px}
    .rs-sku{font-family:var(--font-mono);font-size:13px;font-weight:700;color:var(--sky-700)}
    .rs-row{align-items:center;min-height:44px;padding:8px 16px 8px 20px;border-bottom:1px solid var(--border-subtle);background:var(--bg-surface)}
    .rs-row.sel{background:var(--bg-selected)}
    .rs-kind{padding:0 6px;border:1px solid;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap}
    .rs-kind.ok{background:var(--status-ok-bg);color:var(--status-ok-fg);border-color:oklch(85% 0.07 155)}
    .rs-kind.attn{background:var(--status-attn-bg);color:var(--status-attn-fg);border-color:oklch(85% 0.08 75)}
    .rs-kind.neutral{background:var(--status-neutral-bg);color:var(--status-neutral-fg);border-color:var(--border)}
    .rs-review{padding:0 6px;border:1px solid oklch(82% 0.1 80);border-radius:999px;font-size:11px;font-weight:600;color:var(--amber-700);white-space:nowrap}
    .rs-sgrid{display:grid;grid-template-columns:20px minmax(0,1fr) 64px 56px 170px 88px;gap:12px}
    .rs-srow{align-items:center;min-height:48px;padding:6px 20px;cursor:pointer;transition:background 120ms}
    .rs-srow:hover{background:var(--bg-hover)}
    .rs-srow.open{background:var(--bg-selected)}
    .rs-tl{display:grid;grid-template-columns:18px minmax(0,1fr) auto;gap:10px;padding:8px 0;border-top:1px solid var(--border-subtle)}
    .rs-q-bubble{background:var(--bg-surface-2);border-radius:0 8px 8px 8px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;max-width:760px}
    .rs-ta{box-sizing:border-box;width:100%;border:1px solid var(--border-strong);border-radius:6px;padding:8px 10px;font-size:13px;resize:vertical;outline:none;background:var(--bg-surface)}
    .rs-ta:focus{border-color:var(--sky-500);box-shadow:0 0 0 2px var(--ring)}
    .rs-file{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 8px;border:1px solid var(--border);border-radius:4px;background:var(--bg-surface);font-size:12px;color:var(--fg-1)}
    .rs-thumb{display:block;width:56px;height:56px;border:1px solid var(--border);border-radius:4px;overflow:hidden;cursor:zoom-in;background:var(--bg-surface)}
    .rs-thumb img{width:100%;height:100%;object-fit:cover;display:block}
</style>
<div class="rs">
    {{-- A. Сводка. --}}
    @if($sups->isNotEmpty())
        <section class="rs-card rs-stats">
            <div class="rs-stat"><span class="l">Запрошено</span><span class="v">{{ $sum['asked'] }} {{ trans_choice('поставщик|поставщика|поставщиков', $sum['asked']) }} <small>· {{ $sum['positions'] }} {{ trans_choice('позиция|позиции|позиций', $sum['positions']) }}</small></span></div>
            <div class="rs-stat"><span class="l">Дали цену</span><span class="v">{{ $sum['answered'] }} из {{ $sum['asked'] }} <small>· {{ $sum['offers'] }} {{ trans_choice('предложение|предложения|предложений', $sum['offers']) }}</small></span></div>
            @if($sum['cheapest'])
                <div class="rs-stat"><span class="l">Дешевле всего</span><span class="v">{{ $money($sum['cheapest']['price']) }} {{ $sum['cheapest']['currency'] }} <small>· {{ $sum['cheapest']['supplier'] }}@if($sum['copy']) · копия от {{ $money($sum['copy']['price']) }}@endif</small></span></div>
            @endif
            @if($sum['has_questions'])
                <div class="rs-stat"><span class="l">Вопросы поставщиков</span><span class="v" style="color: {{ $sum['open_questions'] ? 'var(--status-attn-fg)' : 'var(--status-ok-fg)' }}">{{ $sum['open_questions'] ? $sum['open_questions'].' без ответа' : 'все отвечены' }}</span></div>
            @endif
            <div style="flex: 1"></div>
            @if($this->canDispatch)
                <button type="button" class="rs-btn" style="height: 32px; font-size: 13px; padding: 0 12px"
                        x-on:click="const el = document.getElementById('rs-new'); if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 60, behavior: 'smooth' })">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>Новый запрос цен</button>
            @endif
        </section>
    @endif

    {{-- B. Вопросы поставщиков (QuoteParts): открытые — карточкой с ответом, отвеченные — строкой. --}}
    @if($qs->isNotEmpty())
        @php $openQ = $qs->where('open', true); @endphp
        <section class="rs-card">
            <div class="rs-head">
                <h2>Вопросы поставщиков</h2>
                <span class="rs-pill {{ $openQ->isNotEmpty() ? 'attn' : 'ok' }}" style="font-weight: 600">{{ $openQ->isNotEmpty() ? $openQ->count().' без ответа' : 'все отвечены' }}</span>
                <span class="rs-hint">через QuoteParts · ответ уходит всем, кто спросил</span>
            </div>
            @foreach($qs as $q)
                @php $g = $q['group']; $rid = $q['rfq_id']; $gid = $q['group_id']; @endphp
                @if($q['open'])
                    <div wire:key="rsq-{{ $rid }}-{{ $gid }}" style="padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; border-bottom: 1px solid var(--border)">
                        <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap">
                            <span style="font-size: 14px; font-weight: 600">{{ $q['title'] }}</span>
                            <span class="rs-pill attn">ждёт ответа</span>
                            <span class="rs-hint">{{ implode(', ', $q['suppliers']) }} · <span class="mono">{{ trim((string) $q['tag'], '[]') }}</span>{{ $q['at'] ? ' · '.$q['at'] : '' }}</span>
                        </div>
                        @foreach($q['items'] as $it)
                            <div class="rs-q-bubble">
                                @if(count($q['items']) > 1)<span class="rs-hint" style="font-weight: 500; color: var(--fg-2)">{{ $it['supplier'] }}</span>@endif
                                <span style="white-space: pre-line">{{ $it['text'] }}</span>
                                @if($it['original'])<span class="rs-hint">Оригинал: «{{ $it['original'] }}»</span>@endif
                                @if($it['files'] !== [])
                                    @php $gal = collect($it['files'])->filter(fn ($f) => str_starts_with((string) $f['mime'], 'image/'))->values()->map(fn ($f) => ['src' => $f['url'], 'name' => $f['name'], 'dl' => $f['url']])->all(); $gi = 0; @endphp
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center">
                                        @foreach($it['files'] as $f)
                                            @if(str_starts_with((string) $f['mime'], 'image/'))
                                                <a href="{{ $f['url'] }}" class="rs-thumb" title="{{ $f['name'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gal, 'index' => $gi++]) }})"><img src="{{ $f['url'] }}" alt="" loading="lazy"></a>
                                            @else
                                                @include('partials.file-link', ['url' => $f['url'], 'name' => $f['name'], 'mime' => $f['mime'], 'fileClass' => 'rs-file'])
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        @if($canAnswer)
                            @php $needsEn = $this->quotePartsGroupNeedsEnglish($g); @endphp
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px">
                                <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 500; color: var(--fg-2)">Ответ по-русски
                                    <textarea wire:model="qpGroupRu.{{ $rid }}.{{ $gid }}" rows="3" placeholder="Уйдёт всем, кто спросил" class="rs-ta"></textarea></label>
                                @if($needsEn)
                                    <label style="display: flex; flex-direction: column; gap: 4px; font-size: 12px; font-weight: 500; color: var(--fg-2)">
                                        <span>English version <span style="font-weight: 400; color: var(--fg-3)">· поставщик пишет на английском</span></span>
                                        <textarea wire:model="qpGroupEn.{{ $rid }}.{{ $gid }}" rows="3" placeholder="Нажмите «Перевести» или напишите сами" class="rs-ta"></textarea></label>
                                @endif
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap">
                                @if($needsEn)
                                    <button type="button" wire:click="translateQuotePartsGroup({{ $rid }}, {{ $gid }})" wire:loading.attr="disabled" wire:target="translateQuotePartsGroup" class="rs-btn">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg>Перевести</button>
                                @endif
                                <label class="rs-btn ghost">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>Файлы
                                    <input type="file" multiple wire:model="qpGroupFiles.{{ $rid }}.{{ $gid }}" style="display: none" accept="image/*,.pdf,.xlsx,.xls,.doc,.docx,.zip"></label>
                                @foreach($qpGroupFiles[$rid][$gid] ?? [] as $up)
                                    <span class="rs-file" style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $up->getClientOriginalName() }}</span>
                                @endforeach
                                <span wire:loading wire:target="qpGroupFiles.{{ $rid }}.{{ $gid }}" class="rs-hint">загружаем…</span>
                                <button type="button" wire:click="askClientAboutQuotePartsQuestions({{ $rid }})" class="rs-btn ghost" title="Черновик письма клиенту с этими вопросами по-русски, без имён поставщиков — откроется в редакторе">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>Спросить клиента</button>
                                <span style="flex: 1 1 160px"></span>
                                <button type="button" wire:click="closeQuotePartsQuestions({{ $rid }}, {{ $gid }})" wire:confirm="Закрыть вопрос без ответа? Поставщикам ничего не уйдёт, вопрос перейдёт в отвеченные."
                                        class="rs-btn ghost" style="color: var(--fg-3)">Закрыть без ответа</button>
                                <button type="button" wire:click="answerQuotePartsGroup({{ $rid }}, {{ $gid }})" wire:loading.attr="disabled" wire:target="answerQuotePartsGroup" class="rs-btn accent">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>Ответить всем · {{ count($q['supplier_keys']) }}</button>
                            </div>
                        @endif
                    </div>
                @else
                    <div wire:key="rsq-{{ $rid }}-{{ $gid }}" style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; padding: 10px 20px; border-bottom: 1px solid var(--border); font-size: 12px">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex: none; align-self: center; color: var(--status-ok-fg)"><path d="M20 6 9 17l-5-5"/></svg>
                        <span style="font-size: 13px; font-weight: 500">{{ $q['title'] }}</span>
                        <span class="rs-hint">{{ implode(', ', $q['suppliers']) }} · <span class="mono">{{ trim((string) $q['tag'], '[]') }}</span>{{ $q['at'] ? ' · '.$q['at'] : '' }}</span>
                        <span class="rs-pill ok">отвечено{{ $q['answered_at'] ? ' '.$q['answered_at'] : '' }}</span>
                        <span style="flex-basis: 100%; padding-left: 22px; color: var(--fg-2)">{{ collect($q['items'])->pluck('text')->implode(' · ') }}</span>
                    </div>
                @endif
            @endforeach
        </section>
    @endif

    {{-- C. Сравнение цен по позициям. --}}
    @if($cmp !== [])
        <section class="rs-card">
            <div class="rs-head">
                <h2>Сравнение цен</h2>
                <span class="rs-hint" style="flex: 1 1 300px">Все предложения из писем и QuoteParts, сгруппированы по позициям. Выберите цену для 1С в каждой позиции.</span>
                @if($usd)<span class="rs-hint" style="white-space: nowrap">₽ по курсу {{ number_format($usd, 2, ',', ' ') }}</span>@endif
            </div>
            <div style="overflow-x: auto">
                <div style="min-width: 760px">
                    <div class="rs-grid rs-colhead"><span>Поставщик · ответ</span><span style="text-align: right">Цена</span><span>Условия</span><span style="text-align: right">Для 1С</span></div>
                    @foreach($cmp as $p)
                        <div wire:key="rsp-{{ $p['item_id'] }}" class="rs-pos {{ $p['offers'] > 0 ? 'priced' : 'waiting' }}">
                            <div class="rs-pos-head">
                                @if($p['sku'])<span class="rs-sku">{{ $p['sku'] }}</span>@endif
                                <span style="font-size: 14px; font-weight: 600; min-width: 0">{{ $p['name'] }}</span>
                                <span class="rs-pill" style="font-weight: 600; background: var(--rs-ans-bg); color: var(--rs-ans-fg)">ответили {{ $p['answered'] }} из {{ $p['asked'] }}{{ $p['offers'] > $p['answered'] ? ' · предложений '.$p['offers'] : '' }}</span>
                                @if($p['oem'] || $p['brand'] || $p['qty'])
                                    <span class="rs-hint" style="white-space: nowrap">{!! collect([$p['oem'] ? '<span class="mono">'.e($p['oem']).'</span>' : null, $p['brand'] ? e($p['brand']) : null, $p['qty'] ? e($p['qty']).' шт.' : null])->filter()->implode(' · ') !!}</span>
                                @endif
                                <span style="flex: 1"></span>
                                @if($p['cheapest'])<span style="white-space: nowrap"><span class="rs-hint">Дешевле всех</span> <b class="tnum">{{ $money($p['cheapest']['price']) }} {{ $p['cheapest']['currency'] }} · {{ $p['cheapest']['supplier'] }}</b></span>@endif
                                @php $chosenRow = $p['chosen'] ? collect($p['rows'])->firstWhere('id', $p['chosen']) : null; @endphp
                                <span style="white-space: nowrap"><span class="rs-hint">· выбрано</span>
                                    @if($chosenRow)<b style="color: var(--sky-700)">{{ $chosenRow['supplier'] }} · {{ $money($chosenRow['price']) }} {{ $chosenRow['currency'] }}</b>
                                    @elseif($p['choice'])<b style="color: var(--sky-700)" title="Выбор сделан в другом месте (другая заявка, «Актуализация цен»)">{{ $p['choice']->supplier_name }}</b>
                                    @else<b style="color: var(--fg-3); font-weight: 500">не выбрано</b>@endif</span>
                            </div>
                            @foreach($p['rows'] as $r)
                                @php $isSel = $p['chosen'] === $r['id']; @endphp
                                <div wire:key="rsr-{{ $p['item_id'] }}-{{ $r['id'] }}" class="rs-grid rs-row {{ $isSel ? 'sel' : '' }}">
                                    <div style="display: flex; flex-direction: column; gap: 2px; min-width: 0">
                                        <span style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap">
                                            <span style="font-weight: 600">{{ $r['supplier'] }}</span>
                                            <span class="rs-ch {{ $r['channel'] }}">{{ $r['channel'] === 'quoteparts' ? 'QuoteParts' : 'письмо' }}</span>
                                            @if($p['cheapest'] && $p['cheapest']['id'] === $r['id'] && $p['offers'] > 1)<span class="rs-pill" style="font-size: 11px; font-weight: 600; padding: 0 6px; background: var(--emerald-600); color: #fff">дешевле всех</span>@endif
                                        </span>
                                        <span class="rs-hint tnum">{{ $r['when'] }}</span>
                                    </div>
                                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 2px">
                                        @if($r['state'] === 'priced')
                                            @php [$kl, $kt] = $kinds[$r['kind']] ?? [mb_strtolower(\App\Models\QuotePartsOffer::kindLabel($r['kind'])), 'attn']; @endphp
                                            <span class="rs-kind {{ $kt }}">{{ $kl }}</span>
                                            <span class="tnum" style="font-size: 14px; font-weight: 600; white-space: nowrap">{{ $money($r['price']) }} {{ $r['currency'] }}</span>
                                            @if($r['rub'] !== null)<span class="rs-hint tnum" style="white-space: nowrap">≈ {{ $rubf($r['rub']) }} ₽</span>@endif
                                        @elseif($r['state'] === 'refused')
                                            <span class="rs-pill over">отказ</span>
                                        @else
                                            <span class="rs-pill attn">{{ ! empty($r['question']) ? 'есть вопрос' : 'ждём цену' }}</span>
                                        @endif
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px; color: var(--fg-2); min-width: 0">
                                        @if($r['state'] === 'priced')
                                            <span>{{ implode(' · ', $r['terms']) }}</span>
                                            @if($r['review'])<span class="rs-review">на разборе · {{ $r['kind'] === 'copy' ? 'вопрос о копии' : 'не оригинал' }}</span>@endif
                                        @elseif($r['state'] === 'refused')
                                            <span>{{ ! empty($r['note']) ? '«'.\Illuminate\Support\Str::limit($r['note'], 120).'»' : 'цену не дал' }}</span>
                                        @else
                                            <span class="rs-hint">{{ $r['channel'] === 'quoteparts' ? 'запрос '.trim((string) ($r['tag'] ?? ''), '[]') : 'ответа пока нет' }}</span>
                                        @endif
                                    </div>
                                    <div style="display: flex; justify-content: flex-end">
                                        @if($r['state'] === 'priced' && $canPick && $p['cid'] && $r['key'] && ($r['source'] !== 'quoteparts' || $r['ref']))
                                            <button type="button" wire:click="pickPrice({{ $p['item_id'] }}, '{{ $r['id'] }}')" wire:loading.attr="disabled" wire:target="pickPrice"
                                                    class="rs-btn {{ $isSel ? 'picked' : '' }}" style="width: 100%; font-weight: 600" @if($isSel) title="Нажмите, чтобы снять выбор" @endif>
                                                @if($isSel)<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Выбрано@else Выбрать @endif</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="rs-hint" style="padding: 8px 20px; border-top: 1px solid var(--border)">Выбранную цену вносят в корп. базу (1С); после импорта каталога статус цены позиции станет «актуальна». «Дешевле всех» не учитывает копии.</div>
        </section>
    @endif

    {{-- D. Поставщики и переписка. --}}
    @if($sups->isNotEmpty())
        <section class="rs-card">
            <div class="rs-head">
                <h2>Поставщики и переписка</h2>
                <span class="rs-hint">{{ $sups->reject(fn ($s) => ! empty($s['loose']))->count() }} поставщ. · {{ $sups->sum(fn ($s) => count($s['thread'])) }} писем и событий · нажмите на строку, чтобы открыть переписку</span>
            </div>
            <div style="overflow-x: auto">
                <div style="min-width: 700px">
                    <div class="rs-sgrid" style="align-items: center; height: 32px; padding: 0 20px; background: var(--neutral-50); border-bottom: 1px solid var(--border); font-size: 12px; font-weight: 500; color: var(--fg-3)">
                        <span></span><span>Поставщик</span><span style="text-align: right">Позиций</span><span style="text-align: right">Писем</span><span>Статус</span><span style="text-align: right">Последнее</span>
                    </div>
                    @foreach($sups as $s)
                        @php [$stl, $stt] = $statusChip[$s['status']] ?? [$s['status'], 'neutral']; @endphp
                        <div wire:key="rss-{{ $s['id'] }}" x-data="{ open: false }" style="border-bottom: 1px solid var(--border)">
                            <div class="rs-sgrid rs-srow" :class="open && 'open'" x-on:click="open = ! open">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--fg-3); transition: transform 120ms" :style="open && 'transform: rotate(90deg)'"><path d="m9 18 6-6-6-6"/></svg>
                                <div style="display: flex; flex-direction: column; gap: 2px; min-width: 0">
                                    <span style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap"><span style="font-weight: 600">{{ $s['name'] }}</span>@if(empty($s['loose']))<span class="rs-ch {{ $s['channel'] }}">{{ $s['channel'] === 'quoteparts' ? 'QuoteParts' : 'письмо' }}</span>@endif</span>
                                    @if($s['sent'])<span class="rs-hint">запросил {{ $s['by'] ?? '—' }} · {{ $s['sent'] }}</span>@else<span class="rs-hint">копии на rfq@, не привязанные к запросу</span>@endif
                                </div>
                                <span class="tnum" style="text-align: right">{{ $s['positions'] ?? '—' }}</span>
                                <span class="tnum" style="text-align: right">{{ $s['letters'] }}</span>
                                <span class="rs-pill {{ $stt }}" style="justify-self: start">{{ $stl }}</span>
                                <span class="rs-hint tnum" style="text-align: right; color: var(--fg-2); white-space: nowrap">{{ $s['last'] }}</span>
                            </div>
                            <div x-show="open" x-cloak style="padding: 4px 20px 16px 52px">
                                @forelse($s['thread'] as $m)
                                    <div class="rs-tl" x-data="{ full: false }">
                                        <span style="font-weight: 700; color: {{ $m['dir'] === 'out' ? 'var(--sky-700)' : ($m['dir'] === 'in' ? 'var(--emerald-700)' : 'var(--fg-4)') }}">{{ $m['dir'] === 'out' ? '→' : ($m['dir'] === 'in' ? '←' : '·') }}</span>
                                        <div style="display: flex; flex-direction: column; gap: 2px; min-width: 0">
                                            <span style="font-weight: 500">{{ $m['from'] }}</span>
                                            @if(! empty($m['subject']))<span style="font-size: 12px; color: var(--fg-2)">{{ $m['subject'] }}</span>@endif
                                            @if($m['dir'] !== 'event' && mb_strlen((string) $m['body']) > mb_strlen((string) $m['text']) + 3)
                                                <span class="rs-hint" x-show="! full" style="text-wrap: pretty">{{ $m['text'] }} <button type="button" x-on:click="full = true" style="color: var(--sky-700)">весь текст</button></span>
                                                <span x-show="full" x-cloak style="font-size: 12px; color: var(--fg-2); white-space: pre-wrap; max-height: 420px; overflow-y: auto">{{ $m['body'] }}</span>
                                            @else
                                                <span class="rs-hint" style="text-wrap: pretty">{{ $m['text'] }}</span>
                                            @endif
                                            {{-- Вложения: фото — в просмотрщике, PDF — встроенным просмотром, остальное — скачать. --}}
                                            @php
                                                $files = collect($m['files'] ?? [])->map(fn ($f) => ['url' => $f['url'], 'name' => $f['name'], 'mime' => $f['mime'], 'image' => str_starts_with((string) $f['mime'], 'image/')])
                                                    ->concat(collect($m['attachments'] ?? [])->map(fn ($a) => ['url' => route('attachments.preview', $a['id']), 'dl' => route('attachments.download', $a['id']), 'name' => $a['name'], 'mime' => $a['kind'] === 'pdf' ? 'application/pdf' : null, 'image' => $a['kind'] === 'image']))->values();
                                                $gal = $files->where('image', true)->values()->map(fn ($f) => ['src' => $f['url'], 'name' => $f['name'], 'dl' => $f['dl'] ?? $f['url']])->all();
                                                $gi = 0;
                                            @endphp
                                            @if($files->isNotEmpty())
                                                <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 4px">
                                                    @foreach($files as $f)
                                                        @if($f['image'])
                                                            <a href="{{ $f['url'] }}" class="rs-thumb" title="{{ $f['name'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gal, 'index' => $gi++]) }})"><img src="{{ $f['url'] }}" alt="" loading="lazy"></a>
                                                        @else
                                                            @include('partials.file-link', ['url' => $f['url'], 'fileDl' => $f['dl'] ?? null, 'name' => $f['name'], 'mime' => $f['mime'], 'fileClass' => 'rs-file'])
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                        <span class="rs-hint tnum" style="white-space: nowrap">{{ $m['at'] }}</span>
                                    </div>
                                @empty
                                    <div class="rs-hint" style="padding: 8px 0; border-top: 1px solid var(--border-subtle)">Писем пока нет.</div>
                                @endforelse
                                <div style="display: flex; gap: 6px; flex-wrap: wrap; padding-top: 8px; border-top: 1px solid var(--border-subtle)">
                                    @if($s['channel'] === 'quoteparts')
                                        <button type="button" wire:click="refreshQuotePartsRfq({{ $s['rfq_id'] }})" wire:loading.attr="disabled" wire:target="refreshQuotePartsRfq" class="rs-btn">Обновить</button>
                                        <a href="{{ $qpUrl }}/rfqs/{{ $s['remote_id'] }}" target="_blank" rel="noopener" class="rs-btn" style="text-decoration: none">{{ trim((string) $s['tag'], '[]') }} в QuoteParts ↗</a>
                                    @elseif(empty($s['loose']))
                                        <a href="{{ route('suppliers.show', $s['inquiry_id']) }}" wire:navigate class="rs-btn" style="text-decoration: none">Ответить поставщику</a>
                                        <a href="{{ route('suppliers.show', $s['inquiry_id']) }}" wire:navigate class="rs-btn ghost" style="color: var(--sky-700); text-decoration: none">Открыть запрос №{{ $s['inquiry_id'] }}</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
