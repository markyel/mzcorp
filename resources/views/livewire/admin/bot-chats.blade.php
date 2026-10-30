@php
    $f = $this->funnel;
    $pct = fn (int $n) => $f['started'] > 0 ? round($n * 100 / $f['started']) . '%' : '—';
    $inp = 'h-8 px-2 rounded-md border border-[var(--border)] bg-surface text-[12.5px]';
@endphp
<div>
    {{-- Воронка --}}
    <div class="ds-card mb-3">
        <div class="ds-card-header">
            <h3>Боты заявок · воронка</h3>
            <span class="flex-1"></span>
            <select wire:model.live="days" class="{{ $inp }}">
                <option value="7">7 дней</option>
                <option value="30">30 дней</option>
                <option value="90">90 дней</option>
                <option value="3650">всё время</option>
            </select>
        </div>
        <div class="px-[18px] py-3 grid gap-3" style="grid-template-columns: repeat(5, minmax(0, 1fr))">
            @foreach([
                ['Начали диалог', $f['started'], null],
                ['Подтвердили e-mail', $f['verified'], $pct($f['verified'])],
                ['Смотрели цены', $f['priced'], $pct($f['priced'])],
                ['Отправили заявку', $f['requested'], $pct($f['requested'])],
                ['Заявок всего', $f['requests'], 'сообщений от клиентов: ' . $f['messages_in']],
            ] as [$label, $value, $hint])
                <div>
                    <div class="text-[11.5px] text-fg-3">{{ $label }}</div>
                    <div class="text-[20px] font-semibold text-fg-1 leading-tight">{{ $value }}</div>
                    @if($hint)<div class="text-[11px] text-fg-4">{{ $hint }}</div>@endif
                </div>
            @endforeach
        </div>
        <div class="px-[18px] pb-3 text-[11px] text-fg-4">
            По клиентам, впервые написавшим боту за период{{ $channel !== '' ? ' · ' . \App\Enums\MessengerChannel::from($channel)->label() : '' }}.
            «Смотрели цены» — бот хотя бы раз показал позицию по артикулу.
        </div>
    </div>

    <div class="grid gap-3" style="grid-template-columns: minmax(320px, 420px) minmax(0, 1fr)">
        {{-- Список клиентов --}}
        <div class="ds-card self-start">
            <div class="px-3 py-2 flex flex-wrap gap-2 border-b border-[var(--border-subtle)]">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="e-mail, имя, @ник, телефон, ИНН" class="{{ $inp }} flex-1 min-w-[160px]">
                <select wire:model.live="channel" class="{{ $inp }}">
                    <option value="">все</option>
                    @foreach(\App\Enums\MessengerChannel::cases() as $c)
                        <option value="{{ $c->value }}">{{ $c->label() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="filter" class="{{ $inp }}">
                    <option value="">все клиенты</option>
                    <option value="verified">e-mail подтверждён</option>
                    <option value="unverified">не подтвердили</option>
                    <option value="with_requests">с заявками</option>
                    <option value="blocked">заблокированы</option>
                </select>
            </div>
            <div class="text-[11px] text-fg-4 px-3 py-1.5 border-b border-[var(--border-subtle)]">Найдено: {{ $this->total }}</div>
            <div class="divide-y divide-[var(--border-subtle)] max-h-[70vh] overflow-y-auto">
                @forelse($this->chats as $c)
                    <button type="button" wire:click="open({{ $c->id }})" wire:key="bc-{{ $c->id }}"
                            class="w-full text-left px-3 py-2 hover:bg-hover {{ $chatId === $c->id ? 'bg-[var(--sky-50)]' : '' }}">
                        <div class="flex items-center gap-1.5">
                            <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $c->channel->label() }}</span>
                            <b class="text-[12.5px] text-fg-1 truncate">{{ $c->displayName() }}</b>
                            @if($c->blocked_at)<span class="chip text-[10px]" style="background:var(--amber-50);color:var(--amber-800)">блок</span>@endif
                            <span class="flex-1"></span>
                            <span class="text-[10.5px] text-fg-4 shrink-0">{{ $c->last_message_at?->format('d.m H:i') }}</span>
                        </div>
                        <div class="text-[11.5px] text-fg-3 truncate">
                            {{ $c->email ?: 'e-mail не указан' }}{{ $c->email && ! $c->email_verified_at ? ' (не подтверждён)' : '' }}
                            · сообщений {{ $c->messages_count }} · заявок {{ $c->requests_count }}
                        </div>
                    </button>
                @empty
                    <div class="px-3 py-6 text-center text-[12.5px] text-fg-3">Диалогов нет.</div>
                @endforelse
                @if($this->chats->count() < $this->total)
                    <button type="button" wire:click="more" class="w-full py-2 text-[12px] text-sky-700 hover:bg-hover">Показать ещё</button>
                @endif
            </div>
        </div>

        {{-- Диалог --}}
        <div class="ds-card self-start">
            @if($this->selected)
                @php $s = $this->selected; @endphp
                <div class="ds-card-header">
                    <h3>{{ $s->displayName() }}</h3>
                    <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $s->channel->label() }}</span>
                    <span class="flex-1"></span>
                    <button type="button" class="btn btn-sm" wire:click="toggleBlock({{ $s->id }})"
                            wire:confirm="{{ $s->blocked_at ? 'Разблокировать клиента?' : 'Заблокировать? Бот перестанет отвечать этому клиенту.' }}">
                        {{ $s->blocked_at ? 'Разблокировать' : 'Заблокировать' }}</button>
                </div>
                <div class="px-[18px] py-2 text-[12px] text-fg-2 grid gap-x-4 gap-y-0.5 border-b border-[var(--border-subtle)]" style="grid-template-columns: 110px 1fr">
                    <span class="text-fg-3">E-mail</span><span>{{ $s->email ?: '—' }} @if($s->email_verified_at)<span class="text-emerald-700">· подтверждён {{ $s->email_verified_at->format('d.m.Y H:i') }}</span>@endif</span>
                    <span class="text-fg-3">Мессенджер</span><span>{{ trim($s->first_name . ' ' . $s->last_name) ?: '—' }}{{ $s->username ? ' · @' . $s->username : '' }} · id {{ $s->external_user_id }}</span>
                    <span class="text-fg-3">Анкета</span><span>{{ $s->contact_name ?: '—' }} · {{ $s->phone ?: '—' }} · {{ $s->organization?->name ?: ($s->company_name ?: '—') }}{{ $s->inn ? ', ИНН ' . $s->inn : '' }}
                        @if($s->company_name && ! $s->organization_id)<span class="text-fg-4">(организация не привязана)</span>@endif</span>
                    <span class="text-fg-3">Первое / последнее</span><span>{{ $s->created_at?->format('d.m.Y H:i') }} / {{ $s->last_message_at?->format('d.m.Y H:i') }}</span>
                    @if($this->chatRequests->isNotEmpty())
                        <span class="text-fg-3">Заявки</span>
                        <span>@foreach($this->chatRequests as $r)<a href="{{ route('requests.show', $r->id) }}" class="text-sky-700 hover:underline mono">{{ $r->internal_code }}</a> <span class="text-fg-4">({{ $r->status?->label() }})</span>{{ $loop->last ? '' : ', ' }}@endforeach</span>
                    @endif
                </div>
                <div class="px-[18px] py-3 flex flex-col gap-1.5 max-h-[64vh] overflow-y-auto">
                    @forelse($this->messages as $m)
                        @php
                            $in = $m->direction === 'in';
                            $label = match ($m->kind) {
                                'callback' => 'нажата кнопка',
                                'start' => 'начал диалог',
                                'contact' => 'отправил номер',
                                'request' => 'заявка ' . ($m->request?->internal_code ?? ''),
                                'client' => 'менеджеру · ' . ($m->request?->internal_code ?? ''),
                                'manager' => 'менеджер ' . ($m->author?->name ?? '') . ' · ' . ($m->request?->internal_code ?? ''),
                                'notify' => 'уведомление · ' . ($m->request?->internal_code ?? ''),
                                'bot' => 'бот',
                                default => $in ? 'клиент' : 'бот',
                            };
                            $text = $in ? (string) $m->text : html_entity_decode(strip_tags((string) $m->text));
                            $files = (array) (($m->meta ?? [])['files'] ?? []);
                            if ($files === []) {
                                $files = array_map(fn ($a) => (string) ($a['name'] ?? 'файл'), (array) (($m->meta ?? [])['attachments'] ?? []));
                            }
                            $raw = ($m->meta ?? [])['raw'] ?? null;
                        @endphp
                        <div class="flex {{ $in ? 'justify-start' : 'justify-end' }}" wire:key="bm-{{ $m->id }}">
                            <div class="max-w-[80%] rounded-lg px-2.5 py-1.5 text-[12.5px] leading-snug
                                        {{ $in ? ($m->kind === 'callback' ? 'bg-neutral-50 text-fg-3 italic' : 'bg-surface-2 text-fg-1') : ($m->kind === 'manager' ? 'bg-[var(--sky-50)] text-fg-1' : 'bg-neutral-50 text-fg-2') }}">
                                <div class="text-[10px] text-fg-4">{{ $label }} · {{ $m->created_at?->format('d.m H:i:s') }}
                                    @if(! empty(($m->meta ?? [])['send_failed']))<span class="text-amber-800"> · не доставлено</span>@endif
                                    @if(! empty(($m->meta ?? [])['forwarded'])) · переслано @endif</div>
                                <div class="whitespace-pre-line">{{ $text }}</div>
                                @if($files)<div class="text-[11px] text-fg-3 mt-0.5">📎 {{ count($files) }}: {{ implode(', ', $files) }}</div>@endif
                                @if($raw !== null)
                                    <details class="text-[11px] text-amber-800 mt-0.5">
                                        <summary class="cursor-pointer">бот не разобрал сообщение — событие площадки</summary>
                                        <pre class="text-fg-3 mt-1 overflow-y-auto" style="white-space: pre-wrap; word-break: break-all; max-height: 15rem;">{{ json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-[12.5px] text-fg-3">Сообщений нет.</div>
                    @endforelse
                </div>
            @else
                <div class="px-[18px] py-10 text-center text-[12.5px] text-fg-3">Выберите диалог слева.</div>
            @endif
        </div>
    </div>
</div>
