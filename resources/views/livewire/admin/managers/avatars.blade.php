<div class="ds-card p-4 mt-4">
    <div class="flex items-center gap-2 mb-1">
        <h3 class="text-fg-1 font-semibold text-[14px]">Аватарки</h3>
        @if(session('avatarStatus'))
            <span class="text-emerald-700 text-[12px]">{{ session('avatarStatus') }}</span>
        @endif
    </div>
    <p class="text-fg-3 text-[12px] mb-3">
        Нейтральная показывается в списке заявок и в карточке. «Победитель» и «Проигравший» —
        в карточке для закрытых заявок (успех / потеря). PNG/JPG/WEBP, до 1&nbsp;МБ.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach($variants as $key => $cfg)
            @php $url = $user->avatarUrl($key); @endphp
            <div class="border border-border rounded-md p-3 flex flex-col items-center gap-2">
                <div class="text-[12px] font-medium text-fg-2">{{ $cfg[2] }}</div>

                <div class="w-16 h-16 rounded-full overflow-hidden bg-[var(--neutral-200)] flex items-center justify-center"
                     wire:key="avatar-prev-{{ $key }}-{{ $url ? 'set' : 'empty' }}">
                    @if($url)
                        <img src="{{ $url }}" alt="{{ $cfg[2] }}" class="w-16 h-16" style="object-fit:cover;">
                    @else
                        <span class="text-fg-3 text-[10px]">нет</span>
                    @endif
                </div>

                <label class="text-[12px] text-sky-700 hover:underline cursor-pointer">
                    {{ $url ? 'Заменить' : 'Загрузить' }}
                    <input type="file" wire:model.live="{{ $cfg[0] }}" accept="image/png,image/jpeg,image/webp" class="hidden">
                </label>

                <div wire:loading wire:target="{{ $cfg[0] }}" class="text-[11px] text-fg-3">Загрузка…</div>
                @error($cfg[0]) <div class="text-red-700 text-[11px] text-center">{{ $message }}</div> @enderror

                @if($url)
                    <button type="button" wire:click="remove('{{ $key }}')"
                            wire:confirm="Удалить аватарку «{{ $cfg[2] }}»?"
                            class="text-[11.5px] text-red-700 hover:underline">Удалить</button>
                @endif
            </div>
        @endforeach
    </div>
</div>
