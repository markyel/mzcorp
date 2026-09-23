{{-- Кандидат из разбора отзывов конкурента: предложение модели, решение — за человеком. --}}
@php $advantage = $ins->isAdvantage(); @endphp
<div class="p-2 mb-1.5 rounded-md border" wire:key="ins-{{ $ins->id }}"
     style="{{ $ins->isNew()
        ? ($advantage ? 'background:var(--emerald-50);border-color:var(--emerald-200)' : 'background:var(--amber-50);border-color:var(--amber-300)')
        : 'border-color:var(--border-subtle);opacity:.6' }}">
    <div class="text-[12.5px] text-fg-1 break-words">{{ $ins->statement }}</div>

    @if($ins->evidence)
        <div class="text-[11.5px] text-fg-3 italic break-words mt-1">«{{ $ins->evidence }}»</div>
    @endif

    <div class="flex flex-wrap items-center gap-2 mt-1.5">
        @if($advantage && $ins->facetLabel())
            <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $ins->facetLabel() }}</span>
        @elseif(! $advantage && $ins->topic)
            <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $ins->topic }}</span>
        @endif
        <span class="flex-1"></span>
        @if($ins->isNew())
            <button type="button" class="btn btn-xs btn-primary" wire:click="acceptInsight({{ $ins->id }})">
                {{ $advantage ? 'в медиапрофиль' : 'в обратную связь' }}
            </button>
            <button type="button" class="btn btn-xs" wire:click="dismissInsight({{ $ins->id }})">не надо</button>
        @else
            <span class="text-[11px] text-fg-3">{{ $ins->status === 'accepted' ? 'принято' : 'отклонено' }}</span>
        @endif
    </div>
</div>
