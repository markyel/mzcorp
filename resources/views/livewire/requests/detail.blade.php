@php
    use App\Enums\RequestStatus;

    $req = $this->request;
    $email = $req->emailMessage;
    $items = $req->items;
    $assignments = $req->assignments;
    $tabs = $this->tabs;

    $statusChip = $req->status === RequestStatus::New ? 'chip-attn' : 'chip-info';
    $age = $req->created_at?->diffForHumans(['short' => true, 'parts' => 2]) ?? '—';
    $managerInitials = \Illuminate\Support\Str::of($req->assignedUser?->name ?? '?')
        ->substr(0, 1)->upper();

    $titleSuffix = $items->count() > 0
        ? ' · ' . $items->count() . ' ' . match (true) {
            $items->count() % 100 >= 11 && $items->count() % 100 <= 14 => 'позиций',
            $items->count() % 10 === 1 => 'позиция',
            $items->count() % 10 >= 2 && $items->count() % 10 <= 4 => 'позиции',
            default => 'позиций',
        }
        : '';
@endphp

<div class="max-w-[1320px] mx-auto px-6 pt-3 pb-8">

    {{-- ────────── SUBNAV ────────── --}}
    <div class="flex items-center gap-3 mb-3 text-[12.5px]">
        <a href="{{ route('requests.index') }}"
           class="inline-flex items-center gap-1.5 px-2.5 py-1 border border-border rounded-md bg-surface text-sky-700 hover:bg-hover transition-colors">
            ← К списку
        </a>
        <span class="uppercase tracking-wider text-[11.5px] text-fg-3 flex items-center gap-2">
            <span>Заявки</span>
            <span class="text-border-strong">/</span>
            <span class="text-fg-1 font-medium mono">{{ $req->internal_code }}</span>
        </span>
    </div>

    {{-- ────────── HERO ────────── --}}
    <div class="ds-card p-[18px] mb-4 grid gap-4" style="grid-template-columns: 1fr auto">

        <div class="min-w-0">
            {{-- ID row --}}
            <div class="flex items-center gap-2.5 mb-1.5 text-[12px] mono text-fg-3">
                <span class="text-fg-2 font-medium">#{{ $req->internal_code }}</span>
                <button type="button"
                        class="text-fg-3 border border-border px-1.5 py-0.5 rounded text-[10.5px] uppercase tracking-wider hover:bg-hover"
                        onclick="navigator.clipboard.writeText('{{ $req->internal_code }}'); this.textContent='скопировано';">
                    копировать
                </button>
                <span class="text-border-strong">·</span>
                <span>создано {{ $req->created_at?->format('d.m.Y H:i') ?? '—' }}</span>
                @if($req->updated_at && $req->updated_at->ne($req->created_at))
                    <span class="text-border-strong">·</span>
                    <span>обновлено {{ $req->updated_at->format('d.m.Y H:i') }}</span>
                @endif
            </div>

            {{-- Title --}}
            <h1 class="text-[22px] leading-tight font-semibold text-fg-1 mb-1.5"
                style="letter-spacing: -0.005em">
                {{ $req->subject ?: '(без темы)' }}{{ $titleSuffix }}
            </h1>

            {{-- Sub: client / email / mailbox --}}
            <div class="flex items-center gap-2 flex-wrap text-[13px] text-fg-3">
                @if($req->client_name)
                    <span class="text-fg-1 font-medium">{{ $req->client_name }}</span>
                    <span class="text-border-strong">·</span>
                @endif
                @if($req->client_email)
                    <a href="mailto:{{ $req->client_email }}" class="text-sky-700 hover:underline">{{ $req->client_email }}</a>
                @endif
                @if($email?->mailbox)
                    <span class="text-border-strong">·</span>
                    <span>через <span class="text-fg-1">{{ $email->mailbox->email }}</span></span>
                @endif
            </div>

            {{-- Status row --}}
            <div class="mt-3.5 pt-3.5 border-t border-border-subtle flex items-center gap-3.5 flex-wrap text-[12.5px]">
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Статус</span>
                    <span><span class="chip {{ $statusChip }}"><span class="dot"></span>{{ $req->status->label() }}</span></span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">SLA</span>
                    <span class="text-fg-3" title="Доступно в Phase 2">—</span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Менеджер</span>
                    <span class="text-fg-1 inline-flex items-center gap-1.5">
                        @if($req->assignedUser)
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-neutral-200 text-fg-2 text-[9.5px] font-semibold">{{ $managerInitials }}</span>
                            {{ $req->assignedUser->name }}
                        @else
                            <span class="text-fg-3">— не назначен —</span>
                        @endif
                    </span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Sticky</span>
                    <span class="text-fg-3" title="Доступно в Phase 2">—</span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Возраст</span>
                    <span class="text-fg-1 mono">{{ $age }}</span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сумма</span>
                    <span class="text-fg-3" title="Доступно в Phase 2">—</span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сматчено</span>
                    <span class="text-fg-3" title="Доступно в Phase 2">—</span>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex flex-col gap-2 min-w-[200px]">
            <button class="btn btn-primary" disabled title="Доступно в Phase 2">Сформировать КП</button>
            <div class="flex gap-1.5">
                <button class="btn flex-1" disabled title="Доступно в Phase 2">Refresh цен</button>
                <button class="btn flex-1" disabled title="Phase 1.9 — исходящие">Ответить</button>
            </div>
            <div class="flex gap-1.5">
                <button class="btn btn-sm flex-1" disabled title="Доступно в Phase 2">⏸ Пауза</button>
                <button class="btn btn-sm flex-1" disabled title="Доступно в Phase 2">⊘ Переподчинить</button>
            </div>
            <button class="btn btn-sm btn-danger" disabled title="Доступно в Phase 2">Закрыть как «не наша тема»</button>
        </div>
    </div>

    {{-- ────────── TABS ────────── --}}
    <div class="flex border border-border bg-surface px-4 rounded-t-md" style="border-bottom-color: var(--border-subtle)">
        @foreach($tabs as $key => $meta)
            @php $active = $tab === $key; @endphp
            <button type="button"
                    @if(! $meta['disabled']) wire:click="setTab('{{ $key }}')" @else disabled title="Доступно в Phase 2" @endif
                    class="-mb-px px-3.5 py-2.5 text-[12.5px] inline-flex items-center gap-1.5 border-b-2 transition-colors
                           {{ $active
                              ? 'text-fg-1 font-semibold border-accent'
                              : 'text-fg-3 border-transparent ' . ($meta['disabled'] ? 'opacity-55 cursor-not-allowed' : 'hover:text-fg-1 cursor-pointer') }}">
                {{ $meta['label'] }}
                @if($meta['count'] !== null)
                    <span class="text-[10.5px] font-semibold px-1.5 rounded-full
                                 {{ $active ? 'bg-red-50 text-red-700' : 'bg-neutral-100 text-fg-2' }}">
                        {{ $meta['count'] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ────────── TAB PANEL ────────── --}}
    <div class="border border-border border-t-0 rounded-b-md bg-surface-2 p-3.5">

        @switch($tab)

            {{-- ───── ОБЗОР ───── --}}
            @case('overview')
                <div class="grid gap-4" style="grid-template-columns: 1.55fr 1fr">

                    {{-- Left: Позиции preview + Переписка preview --}}
                    <div class="space-y-4">
                        <div class="ds-card">
                            <div class="ds-card-header">
                                <h3>Позиции запроса</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $items->count() }}</span>
                                <span class="flex-1"></span>
                                <button type="button" wire:click="setTab('items')" class="text-sky-700 text-xs hover:underline">все позиции →</button>
                            </div>
                            <div class="ds-card-body p-0">
                                @if($items->isEmpty())
                                    <div class="px-[18px] py-6 text-center text-fg-3 text-sm">
                                        Позиции ещё не распарсены.
                                        <div class="text-[11.5px] mt-1 mono">CLI: <code>php artisan requests:parse-items --apply --request-id={{ $req->id }}</code></div>
                                    </div>
                                @else
                                    <div class="divide-y divide-[var(--border-subtle)]">
                                        @foreach($items->take(5) as $item)
                                            <div class="px-[18px] py-2.5 flex items-start gap-3 text-[12.5px]">
                                                <span class="mono text-fg-3 w-5 text-right">{{ $item->position }}</span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="font-medium text-fg-1 truncate">{{ $item->parsed_name }}</div>
                                                    <div class="text-[11.5px] text-fg-3 flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                                        @if($item->parsed_brand)<span>{{ $item->parsed_brand }}</span>@endif
                                                        @if($item->parsed_article)<span class="text-border-strong">·</span><span class="mono">{{ $item->parsed_article }}</span>@endif
                                                        @if($item->supplier_note)<span class="text-border-strong">·</span><span>{{ \Illuminate\Support\Str::limit($item->supplier_note, 60) }}</span>@endif
                                                    </div>
                                                </div>
                                                <span class="mono text-fg-1 whitespace-nowrap">{{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') ?: '—' }} {{ $item->parsed_unit }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header">
                                <h3>Переписка</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['thread']['count'] }}</span>
                                <span class="flex-1"></span>
                                <button type="button" wire:click="setTab('thread')" class="text-sky-700 text-xs hover:underline">открыть тред →</button>
                            </div>
                            <div class="ds-card-body">
                                @if($email)
                                    <div class="text-[12.5px] text-fg-3">
                                        <span class="text-fg-1 font-medium">{{ $email->from_name ?: $email->from_email }}</span>
                                        · <span class="mono">{{ $email->sent_at?->format('d.m.Y H:i') ?? '—' }}</span>
                                    </div>
                                    <div class="mt-1.5 text-sm text-fg-1 line-clamp-3">
                                        {{ \Illuminate\Support\Str::limit(strip_tags($email->body_plain ?: $email->body_html ?: ''), 320) }}
                                    </div>
                                @else
                                    <div class="text-fg-3 text-sm">Заявка создана не из e-mail (нет переписки).</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Right: Сводка / Refresh / Sticky / Активность --}}
                    <div class="space-y-4">
                        <div class="ds-card">
                            <div class="ds-card-header"><h3>Сводка</h3></div>
                            <div class="ds-card-body">
                                <dl class="grid gap-y-2 gap-x-2.5 text-[13px]" style="grid-template-columns: 110px 1fr">
                                    <dt class="text-fg-3">Канал</dt>
                                    <dd class="text-fg-1 font-medium">
                                        @if($email?->mailbox)
                                            Я.Почта · {{ $email->mailbox->email }}
                                            @if($email->message_id)
                                                <span class="block mt-0.5 text-[11.5px] text-fg-3 font-normal mono truncate">{{ $email->message_id }}</span>
                                            @endif
                                        @else
                                            <span class="text-fg-3 font-normal">—</span>
                                        @endif
                                    </dd>
                                    <dt class="text-fg-3">Источник</dt>
                                    <dd class="text-fg-1 font-medium">{{ $email ? 'Прямое письмо клиента' : 'Не e-mail' }}</dd>
                                    <dt class="text-fg-3">Категория AI</dt>
                                    <dd class="text-fg-1 font-medium">
                                        @if($email?->category)
                                            {{ \App\Enums\EmailCategory::tryFrom($email->category)?->label() ?? $email->category }}
                                            @if($email->category_confidence)
                                                <span class="text-[11.5px] text-fg-3 font-normal">· {{ round($email->category_confidence * 100) }}%</span>
                                            @endif
                                        @else
                                            <span class="text-fg-3 font-normal">не категоризировано</span>
                                        @endif
                                    </dd>
                                    <dt class="text-fg-3">ИНН клиента</dt>
                                    <dd class="text-fg-3 font-normal">— <span class="text-[11.5px]">(Phase 2)</span></dd>
                                    <dt class="text-fg-3">Договор</dt>
                                    <dd class="text-fg-3 font-normal">— <span class="text-[11.5px]">(Phase 2)</span></dd>
                                </dl>
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header"><h3>Refresh цен</h3></div>
                            <div class="ds-card-body text-sm text-fg-3">
                                Привязка поставщиков и refresh-цикл — Phase 2.
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header"><h3>Sticky-цепочка</h3></div>
                            <div class="ds-card-body text-sm text-fg-3">
                                История связей менеджер↔клиент — Phase 2.
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header">
                                <h3>Активность</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['activity']['count'] }}</span>
                                <span class="flex-1"></span>
                                <button type="button" wire:click="setTab('activity')" class="text-sky-700 text-xs hover:underline">все →</button>
                            </div>
                            <div class="ds-card-body">
                                <div class="relative pl-5 text-[12.5px]">
                                    <div class="absolute left-[5px] top-1.5 bottom-1.5 w-px bg-border-strong"></div>
                                    @foreach($assignments->take(5) as $a)
                                        <div class="relative py-1.5">
                                            <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-surface border-[1.5px] border-neutral-400"></span>
                                            <div class="text-fg-1 leading-snug">
                                                Назначен <b class="font-semibold">{{ $a->user?->name ?? '—' }}</b>
                                                @if($a->reason) · {{ $a->reason }} @endif
                                            </div>
                                            <div class="mono text-[11px] text-fg-3 mt-0.5">{{ $a->assigned_at?->format('d.m.Y H:i') }}@if($a->assignedBy) · {{ $a->assignedBy->name }} @endif</div>
                                        </div>
                                    @endforeach
                                    <div class="relative py-1.5">
                                        <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-emerald-700 border-[1.5px] border-emerald-700"></span>
                                        <div class="text-fg-1 leading-snug">Заявка создана</div>
                                        <div class="mono text-[11px] text-fg-3 mt-0.5">{{ $req->created_at?->format('d.m.Y H:i') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @break

            {{-- ───── ПЕРЕПИСКА ───── --}}
            @case('thread')
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Переписка</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['thread']['count'] }}</span>
                        <span class="flex-1"></span>
                        @if($email?->mailbox)
                            <span class="text-[11.5px] text-fg-3">канал: {{ $email->mailbox->email }} ↔ {{ $req->client_email }}</span>
                        @endif
                    </div>

                    @if(! $email)
                        <div class="ds-card-body text-sm text-fg-3">Заявка создана не из e-mail.</div>
                    @else
                        <div>
                            <div class="border-b border-border-subtle">
                                <div class="flex items-center gap-2.5 px-[18px] py-3">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-neutral-200 text-fg-2 text-[11px] font-semibold shrink-0">КЛ</span>
                                    <div class="min-w-0">
                                        <div class="font-medium text-[13px] text-fg-1 truncate">
                                            @if($email->from_name)
                                                {{ $email->from_name }}
                                            @else
                                                {{ $email->from_email }}
                                            @endif
                                        </div>
                                        <div class="text-[11.5px] text-fg-3 mono truncate">
                                            {{ $email->from_email }} · {{ $email->sent_at?->format('d.m.Y в H:i') ?? '—' }}
                                        </div>
                                    </div>
                                    <span class="flex-1"></span>
                                    @if($email->category)
                                        <span class="chip chip-ok"><span class="dot"></span>{{ \App\Enums\EmailCategory::tryFrom($email->category)?->label() ?? $email->category }}</span>
                                    @endif
                                </div>
                                <div class="px-[18px] pb-3.5 pl-[56px] text-[13px] leading-[1.55] text-fg-1">
                                    @if($this->bodyHtml())
                                        <div class="max-w-none">{!! $this->bodyHtml() !!}</div>
                                    @elseif($email->body_plain)
                                        <pre class="whitespace-pre-wrap font-sans text-[13px]">{{ $email->body_plain }}</pre>
                                    @else
                                        <div class="text-fg-3">(пустое тело)</div>
                                    @endif

                                    @if($email->attachments->isNotEmpty())
                                        <div class="mt-3 flex flex-wrap gap-1.5">
                                            @foreach($email->attachments as $att)
                                                <a href="{{ route('attachments.download', $att) }}"
                                                   class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover">
                                                    <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($att->filename, '.')) ?: 'BIN' }}
                                                    </span>
                                                    <span class="truncate max-w-[280px]">{{ $att->filename }}</span>
                                                    @if($att->size_bytes)
                                                        <span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                    @endif
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Compose disabled (Phase 1.9) --}}
                            <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border">
                                <textarea disabled placeholder="Compose будет доступен после Phase 1.9 — OutgoingMailObserver"
                                          class="w-full min-h-[88px] px-3 py-2.5 border border-border-strong rounded-md bg-surface text-fg-3 text-[13px] resize-none cursor-not-allowed"></textarea>
                                <div class="flex items-center gap-2 mt-2.5">
                                    <span class="text-[12px] text-fg-3">Phase 1.9 → Sent-tracking + compose клиенту</span>
                                    <span class="flex-1"></span>
                                    <button class="btn btn-primary" disabled title="Phase 1.9">Отправить</button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                @break

            {{-- ───── ПОЗИЦИИ ───── --}}
            @case('items')
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Позиции запроса</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $items->count() }}</span>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3">источник: {{ $items->first()?->data_source ?? '—' }}</span>
                        <button class="btn btn-sm" disabled title="Доступно в Phase 2">Refresh всех</button>
                    </div>

                    @if($items->isEmpty())
                        <div class="ds-card-body text-center text-fg-3 py-8">
                            Позиции ещё не распарсены.
                            <div class="text-[12px] mt-2 mono">
                                <code>php artisan requests:parse-items --apply --request-id={{ $req->id }}</code>
                            </div>
                        </div>
                    @else
                        <div>
                            <div class="grid items-center px-[18px] gap-2.5 text-[11px] uppercase tracking-wider text-fg-3 font-semibold border-b border-border-subtle"
                                 style="grid-template-columns: 24px 36px 1fr 110px 90px 100px 110px 32px; height: 30px">
                                <span></span><span></span>
                                <span>позиция</span>
                                <span>кол-во</span>
                                <span>цена</span>
                                <span>наличие</span>
                                <span class="text-right">сумма</span>
                                <span></span>
                            </div>
                            @foreach($items as $item)
                                <div class="grid items-center px-[18px] gap-2.5 py-2.5 border-b border-border-subtle text-[12.5px]"
                                     style="grid-template-columns: 24px 36px 1fr 110px 90px 100px 110px 32px">
                                    <span class="mono text-[12px] text-fg-3 text-right">{{ $item->position }}</span>
                                    <span class="w-8 h-8 border border-border rounded-sm bg-app flex items-center justify-center text-[9px] text-fg-3">img</span>
                                    <div class="min-w-0">
                                        <div class="font-medium text-fg-1 truncate">{{ $item->parsed_name ?: '(без названия)' }}</div>
                                        <div class="text-[11.5px] text-fg-3 mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                            @if($item->parsed_brand)<span>{{ $item->parsed_brand }}</span>@endif
                                            @if($item->parsed_article)<span class="mono text-fg-2">{{ $item->parsed_article }}</span>@endif
                                            @if($item->supplier_note)
                                                <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-700 font-medium text-[10.5px]">
                                                    {{ \Illuminate\Support\Str::limit($item->supplier_note, 50) }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="mono text-[12px] text-fg-1 text-right">{{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') ?: '—' }} {{ $item->parsed_unit }}</span>
                                    <span class="text-fg-3 text-right" title="Phase 2">—</span>
                                    <span class="text-fg-3" title="Phase 2">—</span>
                                    <span class="text-fg-3 text-right" title="Phase 2">—</span>
                                    <span class="text-fg-3 text-center cursor-not-allowed" title="Phase 2">···</span>
                                </div>
                            @endforeach

                            <div class="px-[18px] py-3 bg-surface-2 flex items-center gap-3.5 text-[12.5px] border-t border-border-subtle rounded-b-md">
                                <span class="text-fg-3" title="Phase 2">+ добавить позицию</span>
                                <span class="flex-1"></span>
                                <span class="text-fg-3">подытог:</span><span class="text-fg-3 mono">—</span>
                                <span class="text-fg-3">+ НДС 20%:</span><span class="text-fg-3 mono">—</span>
                                <span class="text-fg-3">итого:</span><span class="text-fg-3 mono text-[14px]">—</span>
                            </div>
                        </div>
                    @endif
                </div>
                @break

            {{-- ───── ПОСТАВЩИКИ ───── --}}
            @case('suppliers')
                <div class="ds-card p-8 text-center text-fg-3">
                    <div class="text-fg-1 font-medium mb-1">Поставщики и refresh цен — Phase 2</div>
                    <div class="text-sm">Привязка к поставщикам, batch-refresh запросы, latency-мониторинг.</div>
                </div>
                @break

            {{-- ───── АКТИВНОСТЬ ───── --}}
            @case('activity')
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Активность</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['activity']['count'] }}</span>
                    </div>
                    <div class="ds-card-body">
                        <div class="relative pl-5 text-[12.5px]">
                            <div class="absolute left-[5px] top-1.5 bottom-1.5 w-px bg-border-strong"></div>
                            @foreach($assignments as $a)
                                <div class="relative py-1.5">
                                    <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-surface border-[1.5px] border-neutral-400"></span>
                                    <div class="text-fg-1 leading-snug">
                                        Назначен <b class="font-semibold">{{ $a->user?->name ?? '—' }}</b>
                                        @if($a->reason) · <span class="text-fg-2">{{ $a->reason }}</span>@endif
                                    </div>
                                    <div class="mono text-[11px] text-fg-3 mt-0.5">
                                        {{ $a->assigned_at?->format('d.m.Y H:i') }}
                                        @if($a->assignedBy)
                                            · кем: {{ $a->assignedBy->name }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @if($email)
                                <div class="relative py-1.5">
                                    <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-surface border-[1.5px] border-neutral-400"></span>
                                    <div class="text-fg-1 leading-snug">
                                        Получено письмо от {{ $email->from_name ?: $email->from_email }}
                                        @if($email->attachments->isNotEmpty())
                                            <span class="text-fg-2">· {{ $email->attachments->count() }} вложений</span>
                                        @endif
                                    </div>
                                    <div class="mono text-[11px] text-fg-3 mt-0.5">{{ $email->sent_at?->format('d.m.Y H:i') ?? '—' }}</div>
                                </div>
                            @endif

                            <div class="relative py-1.5">
                                <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-emerald-700 border-[1.5px] border-emerald-700"></span>
                                <div class="text-fg-1 leading-snug">Заявка создана</div>
                                <div class="mono text-[11px] text-fg-3 mt-0.5">{{ $req->created_at?->format('d.m.Y H:i') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                @break

            {{-- ───── ФАЙЛЫ ───── --}}
            @case('files')
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Файлы</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['files']['count'] }}</span>
                    </div>
                    @if(! $email || $email->attachments->isEmpty())
                        <div class="ds-card-body text-fg-3 text-sm">Нет вложений.</div>
                    @else
                        <div class="divide-y divide-[var(--border-subtle)]">
                            @foreach($email->attachments as $att)
                                @php
                                    $ext = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($att->filename, '.')) ?: 'BIN';
                                @endphp
                                <a href="{{ route('attachments.download', $att) }}"
                                   class="flex items-center gap-3 px-[18px] py-2.5 hover:bg-hover transition-colors">
                                    <span class="inline-block w-7 h-9 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[8.5px] font-bold text-center leading-9 shrink-0">{{ $ext }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-fg-1 truncate text-sm">{{ $att->filename }}</div>
                                        <div class="text-[11.5px] text-fg-3 mt-0.5">
                                            {{ $att->mime_type ?: '—' }}
                                            @if($att->size_bytes) · {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB @endif
                                            @if($att->is_inline) · <span class="text-sky-700">inline</span> @endif
                                        </div>
                                    </div>
                                    <span class="text-sky-700 text-xs">скачать →</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
                @break

            {{-- ───── СВЯЗАННЫЕ ───── --}}
            @case('related')
                <div class="ds-card p-8 text-center text-fg-3">
                    <div class="text-fg-1 font-medium mb-1">Связанные заявки и sticky-история — Phase 2</div>
                    <div class="text-sm">Список заявок того же клиента + цепочка sticky-роутинга.</div>
                </div>
                @break

        @endswitch
    </div>
</div>
