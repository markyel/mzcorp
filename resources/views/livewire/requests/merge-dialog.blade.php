@php
    $candidates = $this->candidates;
    $stats = $this->previewStats;
@endphp

<div class="flex-1">
    <button type="button"
            wire:click="show"
            class="btn btn-sm w-full">⊌ Слить дубликат</button>

    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[640px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">Слияние дубликата</h3>
                <div class="text-[12px] text-fg-3 mb-4">
                    Выберите active-заявку этого же клиента — её контент (письма, позиции,
                    уточнения) перенесётся сюда, а сама заявка закроется как duplicate.
                </div>

                @if($candidates->isEmpty())
                    <div class="text-amber-700 text-[12px] mb-4">
                        Не нашлось других active-заявок с этим client_email.
                    </div>
                @else
                    <div class="mb-3">
                        <input type="search"
                               wire:model.live.debounce.300ms="search"
                               placeholder="Поиск по коду / теме…"
                               class="w-full h-[30px] px-2.5 border border-border rounded-md text-[12.5px] outline-none focus:border-[var(--sky-500)]">
                    </div>

                    <div class="space-y-1.5 max-h-[280px] overflow-y-auto mb-3">
                        @foreach($candidates as $c)
                            @php $on = $selectedLoserId === $c->id; @endphp
                            <button type="button"
                                    wire:click="selectLoser({{ $c->id }})"
                                    class="w-full text-left p-2.5 rounded border transition-colors
                                           {{ $on
                                               ? 'border-[var(--sky-500)] bg-[var(--sky-50)]'
                                               : 'border-border bg-[var(--bg-surface)] hover:border-[var(--border-strong)]' }}">
                                <div class="flex items-center gap-2 text-[12.5px]">
                                    <span class="mono font-medium text-fg-1">{{ $c->internal_code }}</span>
                                    <span class="chip {{ $c->status->chipClass() }} text-[10.5px]">
                                        <span class="dot"></span>{{ $c->status->label() }}
                                    </span>
                                    <span class="mono text-fg-4 text-[11px]">· {{ $c->items_count }} поз.</span>
                                    <span class="flex-1"></span>
                                    <span class="text-fg-3 text-[11px]">{{ $c->created_at?->format('d.m.Y') }}</span>
                                </div>
                                @if($c->subject)
                                    <div class="text-[11.5px] text-fg-3 mt-0.5 truncate">{{ $c->subject }}</div>
                                @endif
                                @if($c->assignedUser)
                                    <div class="text-[10.5px] text-fg-4 mt-0.5">менеджер: {{ $c->assignedUser->name }}</div>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif

                @if($stats !== null)
                    @if(!empty($stats['conflicts']))
                        <div class="text-red-700 text-[12px] mb-3 space-y-1">
                            @foreach($stats['conflicts'] as $err)
                                <div>⚠ {{ $err }}</div>
                            @endforeach
                        </div>
                    @else
                        <div class="ds-card p-3 text-[12px] bg-[var(--neutral-50)] mb-3 space-y-0.5">
                            <div class="font-semibold text-fg-2 mb-1 text-[11px] uppercase tracking-wider">Что будет перенесено</div>
                            <div class="flex items-center justify-between">
                                <span class="text-fg-3">Позиций добавится:</span>
                                <span class="mono font-medium text-fg-1">{{ $stats['items_to_add'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-fg-3">Позиций пропущено (уже есть):</span>
                                <span class="mono text-fg-3">{{ $stats['items_to_skip'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-fg-3">Писем переедет:</span>
                                <span class="mono font-medium text-fg-1">{{ $stats['emails_to_move'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-fg-3">Batch'ей уточнений:</span>
                                <span class="mono text-fg-2">{{ $stats['batches_to_move'] }}</span>
                            </div>
                        </div>
                    @endif
                @endif

                @error('selectedLoserId') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    <button type="button"
                            wire:click="confirmMerge"
                            wire:confirm="Слить заявки? Loser будет закрыт как duplicate, отменить нельзя."
                            class="btn btn-primary"
                            @disabled($selectedLoserId === null || ($stats && !empty($stats['conflicts'])))>
                        ⊌ Слить
                    </button>
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                </div>
            </div>
        </div>
    @endif
</div>
