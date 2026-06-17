{{-- Строка заявки-кандидата для привязки счёта. Vars: $cand, $msgId, $number, $prefix --}}
<div wire:key="{{ $prefix }}-{{ $msgId }}-{{ $cand->id }}"
     class="flex items-center gap-2 px-2.5 py-1.5 hover:bg-[var(--bg-hover)]">
    <div class="flex-1 min-w-0">
        <span class="mono font-semibold text-fg-1">{{ $cand->internal_code }}</span>
        <span class="ml-1 chip chip-neutral text-[10px]">{{ $cand->status?->value }}</span>
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
