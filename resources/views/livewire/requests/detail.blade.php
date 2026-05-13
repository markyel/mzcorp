@php
    use App\Enums\MailDirection;
    use App\Enums\RequestStatus;
    use Illuminate\Support\Str;

    $req = $this->request;
    $email = $req->emailMessage;          // trigger-email (для Hero / Сводки)
    $thread = $this->thread;              // полный тред: trigger + reply'и (Phase 1.9 inbound)
    $items = $req->items;
    $assignments = $req->assignments;
    $tabs = $this->tabs;

    // Image-attachment detection: по mime_type и по расширению файла (часть писем
    // приходит без mime, тогда орбитим по расширению).
    $isImageAttachment = function ($a) {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff'];
        if ($a->mime_type && Str::startsWith(strtolower($a->mime_type), 'image/')) {
            return true;
        }
        $ext = strtolower(Str::afterLast($a->filename, '.'));
        return in_array($ext, $imageExtensions, true);
    };

    $statusChip = match ($req->status) {
        RequestStatus::Pending  => 'chip-paused',
        RequestStatus::New      => 'chip-attn',
        RequestStatus::Assigned => 'chip-info',
    };
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
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle min-w-0">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Sticky</span>
                    @php $sticky = $this->sticky; @endphp
                    @if($sticky['links']->isNotEmpty())
                        <span class="flex items-center gap-1.5 flex-wrap"
                              title="Заявки, по которым AssignmentService прицепил эту через совпадение артикула или названия позиции">
                            @foreach($sticky['links'] as $linked)
                                <a href="{{ route('requests.show', $linked) }}"
                                   wire:navigate
                                   class="mono text-[12px] text-sky-700 hover:underline">{{ $linked->internal_code }}</a>{{ ! $loop->last ? ',' : '' }}
                            @endforeach
                        </span>
                    @elseif($sticky['legacy'])
                        <span class="text-fg-2 text-[12px]"
                              title="Старая запись (до Phase 2 sticky visibility) — детали привязки не сохранены">sticky</span>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Возраст</span>
                    <span class="text-fg-1 mono">{{ $age }}</span>
                </div>
                @php
                    // Phase 2 use-case C: hero «Сумма» и «Сматчено» считаем по items.catalogItem.
                    // Сумма — сумма (qty × catalog.price) по всем item'ам с привязкой
                    // (item не сматчен → не в сумме). НДС НЕ добавляем — это «netto»
                    // подытог по каталожным ценам, ту же сумму видно внизу таба
                    // «Позиции». Итог с НДС там же.
                    $heroSubtotal = 0.0;
                    $heroMatched = 0;
                    foreach ($items as $itm) {
                        $p = $itm->catalogItem?->price;
                        $q = (float) ($itm->parsed_qty ?? 0);
                        if ($p !== null && $q > 0) {
                            $heroSubtotal += (float) $p * $q;
                            $heroMatched++;
                        }
                    }
                    $heroTotal = $items->count();
                    $heroHasMoney = $heroSubtotal > 0;
                    $heroMatchedPct = $heroTotal > 0 ? (int) round($heroMatched / $heroTotal * 100) : 0;
                @endphp
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сумма</span>
                    <span class="{{ $heroHasMoney ? 'text-fg-1' : 'text-fg-3' }} mono"
                          title="Подытог по каталожным ценам сматченных позиций (без НДС). С НДС см. внизу таба «Позиции».">
                        {{ $heroHasMoney ? number_format($heroSubtotal, 2, '.', ' ') . ' ₽' : '—' }}
                    </span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сматчено</span>
                    @if($heroTotal === 0)
                        <span class="text-fg-3">—</span>
                    @else
                        <span class="text-fg-1"
                              title="Позиций с привязкой к каталогу. На остальных оператор видит только данные парсера.">
                            {{ $heroMatched }} / {{ $heroTotal }}
                            <span class="text-fg-3 text-[11.5px]">({{ $heroMatchedPct }}%)</span>
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex flex-col gap-2 min-w-[200px]">
            <button class="btn btn-primary" disabled title="Доступно в Phase 2">Сформировать КП</button>
            <div class="flex gap-1.5">
                <button class="btn flex-1" disabled title="Доступно в Phase 2">Refresh цен</button>
                @php
                    $canReply = auth()->id() === $req->assigned_user_id;
                    $lastInbound = $thread->reverse()
                        ->first(fn ($m) => $m->direction === \App\Enums\MailDirection::Inbound);
                @endphp
                @if($canReply)
                    <button type="button"
                            class="btn flex-1"
                            @if($lastInbound)
                                wire:click="$dispatch('open-reply', { messageId: {{ $lastInbound->id }}, requestId: {{ $req->id }} })"
                            @else
                                wire:click="$dispatch('open-compose', { requestId: {{ $req->id }} })"
                            @endif
                    >Ответить</button>
                @else
                    <button class="btn flex-1" disabled title="Отвечать может только назначенный менеджер">Ответить</button>
                @endif
            </div>
            <div class="flex gap-1.5">
                <button class="btn btn-sm flex-1" disabled title="Доступно в Phase 2">⏸ Пауза</button>
                @php
                    $canReassign = auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary']);
                @endphp
                @if($canReassign)
                    <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" />
                @else
                    <button class="btn btn-sm flex-1" disabled title="Только РОП/директор/секретарь">⊘ Переподчинить</button>
                @endif
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
                                        Парсер позиций ещё не отработал.
                                        <div class="text-[11.5px] mt-1 text-fg-4">Задача в очереди — обновите страницу через минуту.</div>
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

                    @if($thread->isEmpty())
                        <div class="ds-card-body text-sm text-fg-3">Заявка создана не из e-mail.</div>
                    @else
                        <div>
                            @foreach($thread as $msg)
                                @php
                                    $isOutbound = $msg->direction === MailDirection::Outbound;
                                    $authorName = $msg->from_name ?: $msg->from_email;
                                    $authorInitial = \Illuminate\Support\Str::of($authorName)->substr(0, 1)->upper();
                                    $catLabel = $msg->category
                                        ? (\App\Enums\EmailCategory::tryFrom($msg->category)?->label() ?? $msg->category)
                                        : null;
                                @endphp
                                <div class="border-b border-border-subtle last:border-b-0">
                                    <div class="flex items-center gap-2.5 px-[18px] py-3">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-[11px] font-semibold shrink-0
                                                     {{ $isOutbound ? 'bg-accent text-fg-on-accent' : 'bg-neutral-200 text-fg-2' }}">
                                            {{ $authorInitial }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-[13px] text-fg-1 truncate">
                                                {{ $authorName }}
                                            </div>
                                            <div class="text-[11.5px] text-fg-3 mono truncate">
                                                {{ $msg->from_email }} · {{ $msg->sent_at?->format('d.m.Y в H:i') ?? '—' }}
                                                @if($msg->mailbox)
                                                    · через {{ $msg->mailbox->email }}
                                                @endif
                                            </div>
                                        </div>
                                        <span class="flex-1"></span>
                                        @if($isOutbound)
                                            <span class="chip chip-info"><span class="dot"></span>исходящее</span>
                                        @elseif($catLabel)
                                            <span class="chip chip-ok"><span class="dot"></span>{{ $catLabel }}</span>
                                        @endif
                                    </div>
                                    <div class="px-[18px] pb-3.5 pl-[56px] text-[13px] leading-[1.55] text-fg-1">
                                        @php $html = $this->bodyHtmlFor($msg); @endphp
                                        @if($html)
                                            {{-- Письмо рендерится в sandbox-iframe (srcdoc), чтобы <style>-блоки
                                                 из тела письма не утекали на страницу и не ломали .btn / шрифты CRM.
                                                 sandbox без allow-scripts — JS из письма не выполнится. --}}
                                            <iframe
                                                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                                srcdoc="{{ $html }}"
                                                loading="lazy"
                                                class="w-full block border-0 bg-surface"
                                                style="height: 0"
                                                x-data
                                                x-init="
                                                    const fit = () => {
                                                        try {
                                                            const h = $el.contentDocument && $el.contentDocument.documentElement
                                                                ? $el.contentDocument.documentElement.scrollHeight
                                                                : 0;
                                                            $el.style.height = (h + 4) + 'px';
                                                        } catch (e) {}
                                                    };
                                                    $el.addEventListener('load', () => {
                                                        try {
                                                            const doc = $el.contentDocument;
                                                            if (!doc) return;
                                                            doc.querySelectorAll('a[href]').forEach(a => {
                                                                a.target = '_blank';
                                                                a.rel = 'noopener noreferrer';
                                                            });
                                                            const s = doc.createElement('style');
                                                            s.textContent = 'html,body{margin:0;padding:0}body{padding:8px 12px;font:13px/1.55 system-ui,-apple-system,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}';
                                                            (doc.head || doc.documentElement).appendChild(s);
                                                            try { new ResizeObserver(fit).observe(doc.documentElement); } catch (e) {}
                                                            fit();
                                                        } catch (e) {}
                                                    });
                                                "
                                            ></iframe>
                                        @elseif($msg->body_plain)
                                            <pre class="whitespace-pre-wrap font-sans text-[13px]">{{ $msg->body_plain }}</pre>
                                        @else
                                            <div class="text-fg-3">(пустое тело)</div>
                                        @endif

                                        @php
                                            $canReplyHere = auth()->id() === $req->assigned_user_id;
                                            $isDraftMsg = (bool) $msg->is_draft;
                                            $isMyDraft = $isDraftMsg && $msg->draft_author_user_id === auth()->id();
                                        @endphp
                                        @if($isDraftMsg)
                                            <div class="mt-2 flex items-center gap-2 text-[11.5px] text-amber-700">
                                                <span class="chip chip-attn"><span class="dot"></span>Черновик</span>
                                                @if($isMyDraft)
                                                    <button type="button"
                                                            wire:click="$dispatch('open-draft', { draftId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                                            class="underline">Продолжить редактирование</button>
                                                @endif
                                            </div>
                                        @elseif($canReplyHere && ! $isOutbound)
                                            <div class="mt-2 flex gap-2">
                                                <button type="button"
                                                        wire:click="$dispatch('open-reply', { messageId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                                        class="btn btn-sm">↩ Ответить</button>
                                                <button type="button"
                                                        wire:click="$dispatch('open-reply-all', { messageId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                                        class="btn btn-sm">↩↩ Ответить всем</button>
                                            </div>
                                        @endif

                                        @if($msg->attachments->isNotEmpty())
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                @foreach($msg->attachments as $att)
                                                    @php
                                                        $isImg = $isImageAttachment($att);
                                                        $previewUrl = route('attachments.preview', $att);
                                                        $downloadUrl = route('attachments.download', $att);
                                                    @endphp
                                                    @if($isImg)
                                                        {{-- Image thumbnail → клик открывает лайтбокс. --}}
                                                        <button type="button"
                                                                x-on:click="$dispatch('open-image', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                                class="block border border-border rounded-md overflow-hidden bg-surface hover:border-border-strong transition-colors text-left"
                                                                title="{{ $att->filename }}">
                                                            <img src="{{ $previewUrl }}"
                                                                 alt="{{ $att->filename }}"
                                                                 loading="lazy"
                                                                 class="w-[140px] h-[100px] object-cover block bg-app">
                                                            <div class="px-2 py-1 max-w-[140px] text-[11px] text-fg-3">
                                                                <span class="block truncate text-fg-1">{{ $att->filename }}</span>
                                                                @if($att->size_bytes)
                                                                    <span>{{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                                @endif
                                                            </div>
                                                        </button>
                                                    @else
                                                        <a href="{{ $downloadUrl }}"
                                                           class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover">
                                                            <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                                                {{ Str::upper(Str::afterLast($att->filename, '.')) ?: 'BIN' }}
                                                            </span>
                                                            <span class="truncate max-w-[280px]">{{ $att->filename }}</span>
                                                            @if($att->size_bytes)
                                                                <span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                            @endif
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            {{-- Phase 1.9 — Compose / Reply form. --}}
                            @if(auth()->id() === $req->assigned_user_id)
                                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[12px] text-fg-3">Ответ клиенту через MyLift — копия сохранится в Sent ящика.</span>
                                        <span class="flex-1"></span>
                                        @if($req->client_email)
                                            <button type="button"
                                                    wire:click="$dispatch('open-compose', { requestId: {{ $req->id }} })"
                                                    class="btn btn-sm">＋ Новое сообщение клиенту</button>
                                        @endif
                                    </div>
                                    <livewire:requests.mail.compose-form
                                        :request-id="$req->id"
                                        wire:key="compose-{{ $req->id }}" />
                                </div>
                            @else
                                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border text-[12px] text-fg-3">
                                    Отвечать на эту заявку может только назначенный менеджер
                                    ({{ $req->assignedUser?->name ?? '— не назначен —' }}).
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
                @break

            {{-- ───── ПОЗИЦИИ ───── --}}
            @case('items')
                @php
                    // Phase 2: очередь LLM-предположений об уточнениях артикулов.
                    // Заполняется decideClarifications при парсинге reply'я.
                    $pendingClarifications = is_array($req->pending_clarifications) ? $req->pending_clarifications : [];
                @endphp

                @if(! empty($pendingClarifications))
                    <div class="ds-card mb-3 border-amber-300">
                        <div class="ds-card-header bg-amber-50">
                            <h3 class="text-amber-900">Предложенные уточнения</h3>
                            <span class="text-[10.5px] font-semibold text-amber-900 bg-amber-100 px-1.5 py-0.5 rounded-full">{{ count($pendingClarifications) }}</span>
                            <span class="flex-1"></span>
                            <span class="text-[11.5px] text-amber-800">LLM нашёл в reply'е уточнения к существующим позициям — проверьте и примите или отклоните</span>
                        </div>
                        <div class="divide-y divide-amber-200">
                            @foreach($pendingClarifications as $clr)
                                @php
                                    $clrId = $clr['id'] ?? null;
                                    $targetPos = $clr['target_position'] ?? null;
                                    $targetItem = $targetPos ? $items->firstWhere('position', (int) $targetPos) : null;
                                    $addArticle = $clr['additional_article'] ?? null;
                                    $addBrand = $clr['additional_brand'] ?? null;
                                    $reasoning = $clr['reasoning'] ?? '';
                                @endphp
                                <div class="px-[18px] py-3 text-[12.5px]" wire:key="clr-{{ $clrId }}">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-fg-3 text-[11px]">К позиции</span>
                                                @if($targetItem)
                                                    <span class="mono text-[11px] text-fg-1">#{{ $targetItem->position }}</span>
                                                    <span class="font-medium text-fg-1">{{ $targetItem->parsed_name }}</span>
                                                    @if($targetItem->parsed_article)
                                                        <span class="mono text-fg-2 text-[11.5px]">{{ $targetItem->parsed_article }}</span>
                                                    @endif
                                                @else
                                                    <span class="text-fg-3">#{{ $targetPos }} (позиция не найдена)</span>
                                                @endif
                                            </div>
                                            <div class="mt-1.5 flex items-center gap-2 flex-wrap">
                                                <span class="text-fg-3 text-[11px]">Добавить:</span>
                                                @if($addArticle)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm bg-amber-100 text-amber-900 font-semibold text-[11.5px] mono">{{ $addArticle }}</span>
                                                @endif
                                                @if($addBrand)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm bg-emerald-100 text-emerald-900 font-medium text-[11.5px]">{{ $addBrand }}</span>
                                                @endif
                                                @if(! $addArticle && ! $addBrand)
                                                    <span class="text-fg-3 italic">пусто (можно только отклонить)</span>
                                                @endif
                                            </div>
                                            @if($reasoning !== '')
                                                <div class="text-[11px] text-fg-3 mt-1.5 italic">{{ $reasoning }}</div>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <button type="button"
                                                    wire:click="applyClarification({{ Js::from($clrId) }})"
                                                    wire:loading.attr="disabled"
                                                    @disabled(! $targetItem || (! $addArticle && ! $addBrand))
                                                    class="btn btn-sm bg-emerald-600 text-white border-emerald-700 hover:bg-emerald-700 disabled:opacity-50">
                                                Применить
                                            </button>
                                            <button type="button"
                                                    wire:click="rejectClarification({{ Js::from($clrId) }})"
                                                    wire:loading.attr="disabled"
                                                    class="btn btn-sm">
                                                Отклонить
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @php
                    // Priority 1: для toggle «Показать удалённые» нужно знать,
                    // есть ли вообще soft-deleted позиции — иначе кнопку прячем.
                    $deletedCount = \App\Models\RequestItem::query()
                        ->where('request_id', $req->id)
                        ->where('is_active', false)
                        ->count();
                    $canEditItems = auth()->id() === $req->assigned_user_id
                        || auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary']);
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Позиции запроса</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $items->count() }}</span>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3">источник: {{ $items->first()?->data_source ?? '—' }}</span>
                        @if($deletedCount > 0)
                            <button wire:click="toggleDeletedItems" class="btn btn-sm">
                                @if($showDeletedItems)
                                    Скрыть удалённые
                                @else
                                    Показать удалённые ({{ $deletedCount }})
                                @endif
                            </button>
                        @endif
                        @if($canEditItems && $items->isNotEmpty())
                            <button type="button"
                                    wire:click="rematchAllItems"
                                    wire:confirm="Сбросить текущие привязки к каталогу для всех позиций (кроме «нет в каталоге») и заново их сматчить через A/B/C? Это займёт несколько секунд."
                                    wire:loading.attr="disabled"
                                    wire:target="rematchAllItems"
                                    class="btn btn-sm"
                                    title="Сбросить привязки и заново сматчить через A/B/C">
                                <span wire:loading.remove wire:target="rematchAllItems">🔄 Refresh всех</span>
                                <span wire:loading wire:target="rematchAllItems">⏳ Пересматчиваем…</span>
                            </button>
                        @else
                            <button class="btn btn-sm" disabled>Refresh всех</button>
                        @endif
                    </div>

                    @if($items->isEmpty())
                        <div class="ds-card-body text-center text-fg-3 py-8">
                            Парсер позиций ещё не отработал.
                            <div class="text-[12px] mt-2 text-fg-4">
                                Задача в очереди — обновите страницу через минуту, либо РОП может перезапустить парсер.
                            </div>
                        </div>
                    @else
                        <div>
                            <div class="grid items-center px-[18px] gap-2.5 text-[11px] uppercase tracking-wider text-fg-3 font-semibold border-b border-border-subtle"
                                 style="grid-template-columns: 24px 36px 1fr 110px 90px 100px 110px 56px; height: 30px">
                                <span></span><span></span>
                                <span>позиция</span>
                                <span>кол-во</span>
                                <span>цена</span>
                                <span>наличие</span>
                                <span class="text-right">сумма</span>
                                <span></span>
                            </div>
                            @foreach($items as $item)
                                @php
                                    // Phase 2.0 KB: chip конфигурация по quality_assessment_status.
                                    $qaStatus = $item->quality_assessment_status;
                                    $qaConfig = match ($qaStatus) {
                                        'sufficient' => ['chip-ok',     'данных достаточно'],
                                        'insufficient' => ['chip-attn', 'данных мало'],
                                        'not_covered' => ['chip-neutral', 'нет правил'],
                                        'assessment_failed' => ['chip-over', 'ошибка KB'],
                                        'internal_catalog_pending' => ['chip-info', 'внутренний SKU · ждёт каталог'],
                                        // Priority 1: оператор подтвердил что M-SKU не появится.
                                        'internal_catalog_not_found' => ['chip-danger', 'нет в каталоге'],
                                        default => null, // not_assessed → не показываем чип
                                    };
                                    $extracted = is_array($item->quality_assessment_payload['extracted_parameters'] ?? null)
                                        ? $item->quality_assessment_payload['extracted_parameters']
                                        : [];
                                @endphp
                                <div wire:key="ri-{{ $item->id }}"
                                     class="grid items-center px-[18px] gap-2.5 py-2.5 border-b border-border-subtle text-[12.5px] {{ $item->is_active ? '' : 'opacity-50 bg-surface-2' }}"
                                     style="grid-template-columns: 24px 36px 1fr 110px 90px 100px 110px 56px">
                                    <span class="mono text-[12px] text-fg-3 text-right">{{ $item->position }}</span>
                                    @php
                                        // Phase 2: превью фото из vision-привязки (request_items.image_attachment_id).
                                        // Если у позиции нет привязки или превью не картинка — дефолтная заглушка.
                                        $itemImg = $item->imageAttachment;
                                        $itemImgIsImage = $itemImg && $isImageAttachment($itemImg);
                                    @endphp
                                    @if($itemImgIsImage)
                                        @php
                                            $itemPreviewUrl = route('attachments.preview', $itemImg);
                                            $itemDownloadUrl = route('attachments.download', $itemImg);
                                        @endphp
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { src: @js($itemPreviewUrl), name: @js($itemImg->filename), dl: @js($itemDownloadUrl) })"
                                                class="w-8 h-8 border border-border rounded-sm overflow-hidden bg-app block shrink-0"
                                                title="{{ $itemImg->filename }} — открыть">
                                            <img src="{{ $itemPreviewUrl }}"
                                                 alt="{{ $itemImg->filename }}"
                                                 loading="lazy"
                                                 class="w-8 h-8 object-cover block">
                                        </button>
                                    @else
                                        <span class="w-8 h-8 border border-border rounded-sm bg-app flex items-center justify-center text-[9px] text-fg-3"
                                              title="Без привязки к фото">img</span>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="font-medium text-fg-1 truncate">{{ $item->parsed_name ?: '(без названия)' }}</div>
                                        <div class="text-[11.5px] text-fg-3 mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                            {{-- Phase 2.0 KB chips: brand + category + qa-status. --}}
                                            @if($item->brand)
                                                <span class="inline-flex items-center px-1.5 rounded-sm bg-emerald-50 text-emerald-800 font-semibold text-[10.5px]"
                                                      title="резолв KB по бренду">
                                                    {{ $item->brand->name }}
                                                </span>
                                            @elseif($item->parsed_brand)
                                                <span title="бренд не резолвлен">{{ $item->parsed_brand }}</span>
                                            @endif
                                            @if($item->kbCategory)
                                                <span class="inline-flex items-center px-1.5 rounded-sm bg-sky-50 text-sky-800 font-medium text-[10.5px]"
                                                      title="{{ $item->kbCategory->slug }}">
                                                    {{ $item->kbCategory->name }}
                                                </span>
                                            @endif
                                            @if($qaConfig)
                                                <span class="chip {{ $qaConfig[0] }} text-[10.5px]"
                                                      title="quality_assessment_status: {{ $qaStatus }}">
                                                    <span class="dot"></span>{{ $qaConfig[1] }}
                                                </span>
                                            @endif
                                            @if($item->parsed_article)<span class="mono text-fg-2">{{ $item->parsed_article }}</span>@endif
                                            {{-- Phase 2 use-case B: бэдж «в каталоге». --}}
                                            @if($item->catalogItem)
                                                <span class="inline-flex items-center px-1.5 rounded-sm bg-violet-50 text-violet-800 font-medium text-[10.5px]"
                                                      title="каталог MyLift: {{ $item->catalogItem->sku }} · {{ $item->catalogItem->brand_article ?: '—' }} · обновлено {{ $item->catalogItem->last_imported_at?->format('d.m.Y') ?? '—' }}">
                                                    в каталоге · {{ $item->catalogItem->sku }}
                                                </span>
                                            @endif
                                            {{-- Phase 2 use-case A: внешняя ссылка на mylift.ru для M-SKU позиций
                                                 (даже если catalog_item_id не привязан — позволяет менеджеру быстро
                                                  открыть карточку товара на сайте каталога). --}}
                                            @php
                                                $mylinkSku = null;
                                                if ($item->catalogItem) {
                                                    $mylinkSku = $item->catalogItem->sku;
                                                } elseif (preg_match('/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u', (string) $item->parsed_article, $mm)) {
                                                    $mylinkSku = $mm[1];
                                                }
                                            @endphp
                                            @if($mylinkSku)
                                                <a href="https://mylift.ru/?text={{ urlencode($mylinkSku) }}&fn=find"
                                                   target="_blank" rel="noopener noreferrer"
                                                   class="inline-flex items-center gap-0.5 px-1.5 rounded-sm bg-sky-50 text-sky-700 hover:text-sky-900 hover:bg-sky-100 font-medium text-[10.5px]"
                                                   title="Открыть на mylift.ru">
                                                    mylift.ru ↗
                                                </a>
                                            @endif
                                            @if($item->supplier_note)
                                                <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-700 font-medium text-[10.5px]">
                                                    {{ \Illuminate\Support\Str::limit($item->supplier_note, 50) }}
                                                </span>
                                            @endif
                                        </div>
                                        @if(! empty($extracted))
                                            {{-- Извлечённые KB-параметры (diameter, current, voltage, ...). --}}
                                            <div class="text-[11px] text-fg-3 mt-1 flex flex-wrap gap-x-2 gap-y-0.5 mono">
                                                @foreach(array_slice($extracted, 0, 6, true) as $slug => $value)
                                                    <span><span class="text-fg-3">{{ $slug }}:</span> <span class="text-fg-2">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</span></span>
                                                @endforeach
                                                @if(count($extracted) > 6)
                                                    <span class="text-fg-3">… +{{ count($extracted) - 6 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    {{-- Кол-во — read-only, редактируется через «⋮ → Редактировать». --}}
                                    <span class="mono text-[12px] text-fg-1 text-right">{{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') ?: '—' }} {{ $item->parsed_unit }}</span>

                                    {{-- Phase 2 use-case C: цена и наличие из catalog_items, если есть привязка. --}}
                                    @php
                                        $ci = $item->catalogItem;
                                        $price = $ci?->price;
                                        $stock = $ci?->stock_available;
                                        $qty = (float) ($item->parsed_qty ?? 0);
                                        $total = ($price !== null && $qty > 0) ? ((float) $price * $qty) : null;
                                        $stockTone = $stock === null ? 'text-fg-3' : ($stock > 0 ? 'text-emerald-700' : 'text-amber-700');
                                    @endphp

                                    <span class="mono text-[12px] {{ $price !== null ? 'text-fg-1' : 'text-fg-3' }} text-right"
                                          title="{{ $ci ? 'из каталога, обновлено ' . ($ci->last_imported_at?->format('d.m.Y') ?? '—') : 'нет привязки к каталогу' }}">
                                        {{ $price !== null ? number_format((float) $price, 2, '.', ' ') . ' ₽' : '—' }}
                                    </span>

                                    <span class="text-[12px] {{ $stockTone }}"
                                          title="{{ $ci ? 'остаток на складе' : 'нет данных' }}">
                                        @if($stock === null)
                                            —
                                        @elseif($stock > 0)
                                            {{ $stock }} шт
                                        @else
                                            нет
                                        @endif
                                    </span>

                                    <span class="mono text-[12px] {{ $total !== null ? 'text-fg-1' : 'text-fg-3' }} text-right">
                                        {{ $total !== null ? number_format($total, 2, '.', ' ') . ' ₽' : '—' }}
                                    </span>

                                    @if($canEditItems)
                                        @if(! $item->is_active)
                                            {{-- Soft-deleted: кнопка восстановления вместо меню. --}}
                                            <button type="button"
                                                    wire:click="restoreItem({{ $item->id }})"
                                                    class="text-emerald-700 hover:text-emerald-900 text-center text-[14px]"
                                                    title="Восстановить позицию">↩</button>
                                        @else
                                        <div class="flex items-center justify-end gap-0.5">
                                            {{-- Лупа — быстрый vector-поиск похожих позиций каталога. --}}
                                            @if($item->parsed_name || $item->parsed_article)
                                                <button type="button"
                                                        @click="$dispatch('open-catalog-similar', { itemId: {{ $item->id }} })"
                                                        class="text-fg-3 hover:text-fg-1 text-[13px] px-1 leading-none"
                                                        title="Найти похожие позиции в каталоге">🔍</button>
                                            @endif
                                            <div x-data="{ open: false }" class="relative"
                                                 @click.outside="open = false">
                                                <button type="button"
                                                        @click="open = !open"
                                                        class="text-fg-2 hover:text-fg-1 text-[16px] leading-none px-1"
                                                        title="Действия">⋮</button>
                                                <div x-show="open" x-cloak x-transition.origin.top.right
                                                     class="absolute right-0 top-full mt-1 z-30 w-[220px] py-1 bg-surface border border-border rounded-md shadow-lg text-left text-[12.5px]">
                                                    @if($item->parsed_name)
                                                        <button type="button"
                                                                @click="open = false; $dispatch('open-item-edit', { itemId: {{ $item->id }} })"
                                                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                                            📝 Редактировать…
                                                        </button>
                                                    @endif
                                                    @if($item->parsed_name || $item->parsed_article)
                                                        <button type="button"
                                                                @click="open = false; $wire.refreshItemCatalog({{ $item->id }})"
                                                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                                            🔄 Обновить из каталога
                                                        </button>
                                                    @endif
                                                    @if($item->catalog_item_id)
                                                        <button type="button"
                                                                @click="open = false; $wire.unbindItemCatalog({{ $item->id }})"
                                                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                                            🔓 Отвязать от каталога
                                                        </button>
                                                    @endif
                                                    @if($item->parsed_name || $item->parsed_article)
                                                        <button type="button"
                                                                @click="open = false; $dispatch('open-catalog-similar', { itemId: {{ $item->id }} })"
                                                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                                            🔍 Похожие из каталога…
                                                        </button>
                                                    @endif
                                                    <button type="button"
                                                            @click="open = false; $dispatch('open-catalog-link', { itemId: {{ $item->id }} })"
                                                            class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                                        🔗 Привязать вручную…
                                                    </button>
                                                    @if($qaStatus === 'internal_catalog_pending')
                                                        <button type="button"
                                                                @click="open = false"
                                                                wire:click="markItemCatalogNotFound({{ $item->id }})"
                                                                wire:confirm="Подтвердить, что SKU «{{ $item->parsed_article }}» отсутствует в каталоге?"
                                                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                                            ❌ Нет в каталоге
                                                        </button>
                                                    @endif
                                                    <div class="my-1 border-t border-border-subtle"></div>
                                                    <button type="button"
                                                            @click="open = false"
                                                            wire:click="softDeleteItem({{ $item->id }})"
                                                            wire:confirm="Удалить позицию «{{ \Illuminate\Support\Str::limit($item->parsed_name ?: 'позиция #' . $item->position, 40) }}»?"
                                                            class="block w-full text-left px-3 py-1.5 hover:bg-red-50 text-red-700">
                                                        🗑 Удалить позицию
                                                    </button>
                                                </div>
                                            </div>
                                        </div>{{-- /flex action-cell --}}
                                        @endif
                                    @else
                                        <span class="text-fg-3 text-center" title="Менеджер заявки">⋮</span>
                                    @endif
                                </div>
                            @endforeach

                            @php
                                // Phase 2 use-case C: подытог по каталожным ценам тех позиций,
                                // у которых есть привязка и непустое qty. Если у части позиций
                                // привязки нет — они в подсчёт не идут, в подсказке покажем
                                // сколько посчитано.
                                $subtotal = 0.0;
                                $countedItems = 0;
                                foreach ($items as $itm) {
                                    $p = $itm->catalogItem?->price;
                                    $q = (float) ($itm->parsed_qty ?? 0);
                                    if ($p !== null && $q > 0) {
                                        $subtotal += (float) $p * $q;
                                        $countedItems++;
                                    }
                                }
                                $vatPercent = (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22));
                                $vat = $subtotal * $vatPercent / 100;
                                $totalAll = $subtotal + $vat;
                                $hasAnyPrice = $countedItems > 0;
                                $totalsTitle = $hasAnyPrice
                                    ? "посчитано позиций: {$countedItems} из {$items->count()}"
                                    : 'ни одна позиция не привязана к каталогу';
                            @endphp
                            <div class="px-[18px] py-3 bg-surface-2 flex items-center gap-3.5 text-[12.5px] border-t border-border-subtle rounded-b-md"
                                 title="{{ $totalsTitle }}">
                                <span class="text-fg-3" title="Phase 2">+ добавить позицию</span>
                                <span class="flex-1"></span>
                                <span class="text-fg-3">подытог:</span><span class="{{ $hasAnyPrice ? 'text-fg-1' : 'text-fg-3' }} mono">{{ $hasAnyPrice ? number_format($subtotal, 2, '.', ' ') . ' ₽' : '—' }}</span>
                                <span class="text-fg-3">+ НДС {{ rtrim(rtrim(number_format($vatPercent, 1, '.', ''), '0'), '.') }}%:</span><span class="{{ $hasAnyPrice ? 'text-fg-1' : 'text-fg-3' }} mono">{{ $hasAnyPrice ? number_format($vat, 2, '.', ' ') . ' ₽' : '—' }}</span>
                                <span class="text-fg-3">итого:</span><span class="{{ $hasAnyPrice ? 'text-fg-1' : 'text-fg-3' }} mono text-[14px]">{{ $hasAnyPrice ? number_format($totalAll, 2, '.', ' ') . ' ₽' : '—' }}</span>
                            </div>
                        </div>
                    @endif

                    {{-- Priority 1: модалки ручных действий с позициями.
                         Single-instance, слушают $dispatch события от строк. --}}
                    @if($canEditItems)
                        <livewire:requests.items.item-edit-dialog
                            :request-id="$req->id"
                            wire:key="item-edit-{{ $req->id }}" />
                        <livewire:requests.items.item-catalog-link-dialog
                            :request-id="$req->id"
                            wire:key="item-catalog-link-{{ $req->id }}" />
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
                @php
                    // Все вложения треда: trigger-email + reply'и.
                    $allAttachments = $thread->flatMap(fn ($m) => $m->attachments)->values();
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Файлы</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $allAttachments->count() }}</span>
                    </div>
                    @if($allAttachments->isEmpty())
                        <div class="ds-card-body text-fg-3 text-sm">Нет вложений.</div>
                    @else
                        <div class="divide-y divide-[var(--border-subtle)]">
                            @foreach($allAttachments as $att)
                                @php
                                    $ext = Str::upper(Str::afterLast($att->filename, '.')) ?: 'BIN';
                                    $isImg = $isImageAttachment($att);
                                    $previewUrl = route('attachments.preview', $att);
                                    $downloadUrl = route('attachments.download', $att);
                                @endphp
                                <div class="flex items-center gap-3 px-[18px] py-2.5 hover:bg-hover transition-colors">
                                    @if($isImg)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                class="shrink-0 block border border-border rounded-sm overflow-hidden bg-app"
                                                title="{{ $att->filename }}">
                                            <img src="{{ $previewUrl }}"
                                                 alt="{{ $att->filename }}"
                                                 loading="lazy"
                                                 class="w-12 h-12 object-cover block">
                                        </button>
                                    @else
                                        <span class="inline-block w-7 h-9 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[8.5px] font-bold text-center leading-9 shrink-0">{{ $ext }}</span>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="text-fg-1 truncate text-sm">{{ $att->filename }}</div>
                                        <div class="text-[11.5px] text-fg-3 mt-0.5">
                                            {{ $att->mime_type ?: '—' }}
                                            @if($att->size_bytes) · {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB @endif
                                            @if($att->is_inline) · <span class="text-sky-700">inline</span> @endif
                                        </div>
                                    </div>
                                    @if($isImg)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                class="text-sky-700 text-xs hover:underline">просмотр →</button>
                                    @endif
                                    <a href="{{ $downloadUrl }}" class="text-sky-700 text-xs hover:underline">скачать →</a>
                                </div>
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

    {{-- ────────── LIGHTBOX (просмотр картинок) ──────────
         Открывается событием window:open-image с detail {src, name, dl}.
         Закрытие: Esc, клик по бэкдропу, кнопка «Закрыть».
         Inline-стили для критичной геометрии: защита от случая, когда
         Tailwind-бандл не пересобрали. Изображение центрируется через
         absolute + transform (а не flex), чтобы Alpine x-show не сбивал
         display root-элемента. --}}
    <div x-data="{ lbOpen: false, lbSrc: '', lbName: '', lbDl: '' }"
         x-on:open-image.window="lbOpen = true; lbSrc = $event.detail.src; lbName = $event.detail.name; lbDl = $event.detail.dl"
         x-on:keydown.escape.window="lbOpen = false">
        <div x-show="lbOpen"
             x-transition.opacity.duration.150ms
             style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.82); cursor: zoom-out;"
             x-on:click.self="lbOpen = false">
            <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; align-items: center; gap: 8px; z-index: 2;">
                <span style="color: rgba(255,255,255,0.92); font-size: 12px; font-family: var(--font-mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="lbName"></span>
                <a :href="lbDl" download class="btn btn-sm" x-on:click.stop>Скачать</a>
                <button type="button" class="btn btn-sm" x-on:click.stop="lbOpen = false">Закрыть</button>
            </div>
            <img :src="lbSrc" :alt="lbName"
                 style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: calc(100vw - 48px); max-height: calc(100vh - 80px); width: auto; height: auto; object-fit: contain; cursor: default; display: block; box-shadow: 0 8px 32px rgba(0,0,0,0.5);"
                 x-on:click.stop>
        </div>
    </div>
</div>
