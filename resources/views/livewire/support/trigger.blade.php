<div wire:poll.30s class="relative inline-flex">
    @php $count = $this->unreadCount; @endphp
    <button type="button"
            data-support-trigger
            data-route-name="{{ request()->route()?->getName() ?? '' }}"
            class="relative inline-flex items-center justify-center w-8 h-8 rounded-md hover:bg-[var(--bg-surface-2)]"
            style="color: var(--accent);"
            title="Связь с создателем{{ $count > 0 ? ' (новых ответов: ' . $count . ')' : '' }}">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="1.75"
             stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
            <path d="M12 3 L21 20 L3 20 Z"/>
            <line x1="6.5" y1="17.2" x2="17.5" y2="17.2"/>
            <path d="M7.2 12.2 C 9 10.6, 15 10.6, 16.8 12.2 C 15 13.9, 9 13.9, 7.2 12.2 Z"/>
            <circle cx="12" cy="12.2" r="1.25" fill="currentColor" stroke="none"/>
        </svg>
        @if($count > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-[var(--accent)] text-white text-[10px] font-bold leading-[16px] text-center">
                {{ $count > 99 ? '99+' : $count }}
            </span>
        @endif
    </button>
</div>
