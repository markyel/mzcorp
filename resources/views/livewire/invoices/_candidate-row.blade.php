{{-- Строка заявки-кандидата для привязки счёта. Vars: $cand, $msgId, $number, $prefix --}}
<div wire:key="{{ $prefix }}-{{ $msgId }}-{{ $cand->id }}" x-data="{ open: false }"
     class="px-2.5 py-1.5 hover:bg-[var(--bg-hover)]">
    <div class="flex items-center gap-2">
        <div class="flex-1 min-w-0">
            <span class="mono font-semibold text-fg-1">{{ $cand->internal_code }}</span>
            <span class="ml-1 chip chip-neutral text-[10px]">{{ $cand->status?->value }}</span>
            @if(!empty($badge ?? null))
                <span class="ml-1 text-[10px] mono font-semibold text-[var(--emerald-700,#047857)]" title="Совпавшие M-артикулы">🎯 {{ $badge }}</span>
            @endif
            @if(!empty($ownManager ?? false))
                <span class="ml-1 text-[10px] font-semibold text-[var(--sky-700)]" title="Заявка назначена менеджеру, отправившему счёт">👤 свой</span>
            @endif
            @if($cand->relationLoaded('items') && $cand->items->isNotEmpty())
                <button type="button" @click="open = !open"
                        class="ml-1 text-[10.5px] text-[var(--sky-700)] hover:underline align-baseline">
                    <span x-show="!open">позиции ({{ $cand->items->count() }}) ▾</span>
                    <span x-show="open" x-cloak>скрыть ▴</span>
                </button>
            @endif
            <div class="text-[11px] text-fg-3 truncate">
                {{ $cand->client_name ?: $cand->client_email }} ·
                {{ \Illuminate\Support\Str::limit($cand->subject, 50) }}
                @if($cand->assignedUser) · {{ $cand->assignedUser->name }} @endif
            </div>
        </div>
        <button type="button"
                wire:click="attach({{ $cand->id }})"
                wire:confirm="Привязать счёт {{ $number ?? '' }} к заявке {{ $cand->internal_code }}?"
                class="btn btn-sm btn-primary shrink-0">Выбрать</button>
    </div>

    @if($cand->relationLoaded('items') && $cand->items->isNotEmpty())
        <div x-show="open" x-cloak class="mt-1 ml-1 pl-2 border-l-2 border-border space-y-0.5">
            @foreach($cand->items as $it)
                <div class="text-[11px] text-fg-2 leading-snug">
                    <span class="text-fg-4">{{ $loop->iteration }}.</span>
                    {{ $it->parsed_name ?: '—' }}
                    @if($it->parsed_article)
                        <span class="mono text-fg-3">[{{ $it->parsed_article }}]</span>
                    @endif
                    @if($it->parsed_qty !== null)
                        <span class="text-fg-3">— {{ rtrim(rtrim((string) $it->parsed_qty, '0'), '.') ?: $it->parsed_qty }} {{ $it->parsed_unit }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
