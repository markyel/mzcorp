@php
    use App\Enums\MailDirection;
    $managers = $this->managers;
    $thread = $this->thread;
    $items = $this->items;
    $countByEmail = $this->itemCountByEmail;
@endphp

{{-- flex-1 — чтобы trigger ровно делил место в parent flex-row action-панели. --}}
<div class="flex-1">
    <button type="button"
            wire:click="show"
            class="btn btn-sm w-full"
            title="Вынести часть переписки и позиций в отдельную заявку">✂ Разъединить заявку</button>

    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="close">
            {{-- Карта: фиксированная высота, шапка/подвал не скроллятся, тело
                 (списки писем/позиций) прокручивается внутри. --}}
            <div class="ds-card p-0 w-full max-w-[640px] max-h-[85vh] flex flex-col overflow-hidden" wire:click.stop>
                {{-- Шапка --}}
                <div class="px-5 pt-5 pb-3 border-b border-border-subtle shrink-0">
                    <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                        Разъединение заявки <span class="mono text-fg-2">{{ $request->internal_code }}</span>
                    </h3>
                    <div class="text-[12px] text-fg-3">
                        Выберите письма чужого потока и их позиции — они уйдут в новую заявку.
                        В исходной должно остаться хотя бы одно письмо; seed-письмо вынести нельзя.
                    </div>
                    @error('split') <div class="text-red-700 text-[12.5px] mt-2">{{ $message }}</div> @enderror
                </div>

                <form wire:submit="save" class="flex flex-col min-h-0 flex-1">
                    {{-- Прокручиваемое тело --}}
                    <div class="px-5 py-3 space-y-4 overflow-y-auto flex-1 min-h-0">
                    {{-- Письма --}}
                    <div>
                        <div class="text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Письма</div>
                        <div class="border border-border rounded-md divide-y divide-border-subtle max-h-[240px] overflow-y-auto">
                            @foreach($thread as $msg)
                                @php
                                    $isOut = $msg->direction === MailDirection::Outbound->value || $msg->direction === MailDirection::Outbound;
                                    $isSeed = (int) $request->email_message_id === (int) $msg->id;
                                    $cnt = $countByEmail[$msg->id] ?? 0;
                                @endphp
                                <label class="flex items-start gap-2 px-3 py-2 text-[12.5px] {{ $isSeed ? 'opacity-50' : 'cursor-pointer hover:bg-[var(--bg-hover)]' }}">
                                    <input type="checkbox"
                                           value="{{ $msg->id }}"
                                           wire:model.live="selectedEmailIds"
                                           @disabled($isSeed)
                                           class="mt-0.5">
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-1.5">
                                            <span class="chip {{ $isOut ? 'chip-info' : 'chip-ok' }}"><span class="dot"></span>{{ $isOut ? 'исходящее' : 'входящее' }}</span>
                                            @if($cnt > 0)
                                                <span class="chip chip-attn"><span class="dot"></span>источник {{ $cnt }} поз.</span>
                                            @endif
                                            @if($isSeed)
                                                <span class="text-[11px] text-fg-4">seed — нельзя вынести</span>
                                            @endif
                                        </span>
                                        <span class="block text-fg-1 truncate mt-0.5">{{ $msg->subject ?: '(без темы)' }}</span>
                                        <span class="block text-fg-3 truncate text-[11.5px]">
                                            {{ $msg->from_name ?: $msg->from_email }} · {{ optional($msg->sent_at)->format('d.m.Y H:i') ?? '—' }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Позиции (предвыбраны по провенансу выбранных писем) --}}
                    <div>
                        <div class="text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">
                            Позиции в новую заявку
                            <span class="text-fg-4 normal-case font-normal">— предвыбраны по выбранным письмам, можно поправить</span>
                        </div>
                        @if($items->isEmpty())
                            <div class="text-[12px] text-fg-3">В заявке нет активных позиций.</div>
                        @else
                            <div class="border border-border rounded-md divide-y divide-border-subtle max-h-[220px] overflow-y-auto">
                                @foreach($items as $it)
                                    <label class="flex items-start gap-2 px-3 py-2 text-[12.5px] cursor-pointer hover:bg-[var(--bg-hover)]">
                                        <input type="checkbox"
                                               value="{{ $it->id }}"
                                               wire:model="selectedItemIds"
                                               class="mt-0.5">
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-fg-1 truncate">{{ $it->position }}. {{ $it->parsed_name }}</span>
                                            <span class="block text-fg-3 text-[11.5px]">
                                                {{ rtrim(rtrim((string) $it->parsed_qty, '0'), '.') }} {{ $it->parsed_unit }}
                                                @if($it->source_email_message_id)
                                                    · из письма #{{ $it->source_email_message_id }}
                                                @else
                                                    · <span class="text-amber-700">источник не определён</span>
                                                @endif
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Назначение новой заявки --}}
                    <div>
                        <div class="text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Назначить новую заявку</div>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-[12.5px] cursor-pointer">
                                <input type="radio" value="auto" wire:model.live="assignMode">
                                Авто-распределение
                                <span class="text-fg-4 text-[11.5px]">(round-robin + sticky; письмо в личный ящик → его владелец)</span>
                            </label>
                            <label class="flex items-center gap-2 text-[12.5px] cursor-pointer">
                                <input type="radio" value="manager" wire:model.live="assignMode">
                                Конкретному менеджеру
                            </label>
                            @if($assignMode === 'manager')
                                <select wire:model="assignToUserId"
                                        class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                                    <option value="">— выберите —</option>
                                    @foreach($managers as $m)
                                        <option value="{{ $m->id }}">{{ $m->name }} · {{ $m->email }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    </div>
                    </div>{{-- /прокручиваемое тело --}}

                    {{-- Подвал — зафиксирован, не скроллится --}}
                    <div class="px-5 py-3 flex items-center gap-2 border-t border-border-subtle shrink-0">
                        <button type="submit" class="btn btn-primary"
                                @disabled(empty($selectedEmailIds))>Выделить в новую заявку</button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
