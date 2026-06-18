<div class="space-y-4">
    @php $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp

    {{-- Заголовок --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('suppliers.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Поставщики</a>
        <h2 class="text-[16px] font-semibold text-fg-1">{{ $inquiry->supplier_name ?: $inquiry->supplier_email }}</h2>
        <span class="chip {{ $inquiry->status === 'closed' ? 'chip-neutral' : 'chip-sky' }} text-[11px]">{{ $inquiry->status === 'closed' ? 'закрыт' : 'открыт' }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Реквизиты запроса --}}
        <div class="lg:col-span-2 ds-card">
            <div class="ds-card-header"><h3>Запрос поставщику</h3></div>
            <div class="ds-card-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Название поставщика</label>
                        <input type="text" wire:model="supplier_name" class="{{ $inputCls }}">
                        @error('supplier_name') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">E-mail</label>
                        <input type="text" value="{{ $inquiry->supplier_email }}" class="{{ $inputCls }} mono" disabled>
                    </div>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Тема запроса</label>
                    <div class="text-[13px] text-fg-2">{{ $inquiry->subject ?: '—' }}</div>
                </div>
                @if($inquiry->relatedRequest)
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Клиентская заявка</label>
                        <a href="{{ route('requests.show', $inquiry->relatedRequest->id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $inquiry->relatedRequest->internal_code }}</a>
                    </div>
                @endif
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Заметки</label>
                    <textarea wire:model="notes" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="button" wire:click="save" class="btn btn-sm btn-primary">Сохранить</button>
                    <button type="button" wire:click="toggleStatus" class="btn btn-sm">{{ $inquiry->status === 'closed' ? 'Открыть' : 'Закрыть запрос' }}</button>
                </div>
            </div>
        </div>

        {{-- Кто пометил --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Информация</h3></div>
            <div class="ds-card-body space-y-2 text-[12.5px]">
                <div class="flex justify-between gap-2"><span class="text-fg-3">Пометил</span><span class="text-fg-1">{{ $inquiry->createdBy?->name ?? '—' }}</span></div>
                <div class="flex justify-between gap-2"><span class="text-fg-3">Создан</span><span class="text-fg-2 mono">{{ $inquiry->created_at?->format('d.m.Y H:i') }}</span></div>
                <div class="flex justify-between gap-2"><span class="text-fg-3">Писем в треде</span><span class="text-fg-2 mono">{{ $this->messages->count() }}</span></div>
            </div>
        </div>
    </div>

    {{-- Переписка --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Переписка с поставщиком</h3></div>
        <div class="ds-card-body space-y-3">
            @forelse($this->messages as $m)
                @php $isInbound = $m->direction === \App\Enums\MailDirection::Inbound; @endphp
                <div wire:key="msg-{{ $m->id }}" class="rounded-md border border-border-subtle p-3 {{ $isInbound ? 'bg-surface' : 'bg-surface-2' }}">
                    <div class="flex items-center gap-2 text-[11.5px] text-fg-3 mb-1.5 flex-wrap">
                        <span class="chip {{ $isInbound ? 'chip-info' : 'chip-neutral' }} text-[10px]">{{ $isInbound ? '← от поставщика' : '→ наше' }}</span>
                        <span class="mono">{{ $m->from_email }}</span>
                        <span class="flex-1"></span>
                        <span class="mono">{{ $m->sent_at?->format('d.m.Y H:i') ?? '—' }}</span>
                    </div>
                    @if($m->subject)<div class="text-[12.5px] text-fg-1 font-medium mb-1">{{ $m->subject }}</div>@endif
                    <div class="text-[12.5px] text-fg-2 whitespace-pre-line">{{ \Illuminate\Support\Str::limit(trim((string) $m->body_plain), 800) }}</div>
                </div>
            @empty
                <div class="text-sm text-fg-3">Писем нет.</div>
            @endforelse
        </div>
    </div>

    {{-- Удаление --}}
    <div class="ds-card">
        <div class="ds-card-body flex items-center justify-between gap-3 flex-wrap">
            <div class="text-[12px] text-fg-3">Удалить запрос поставщику. Письма не удаляются — только открепляются от запроса.</div>
            <button type="button" wire:click="deleteInquiry" wire:confirm="Удалить запрос поставщику? Письма останутся, но открепятся." class="btn btn-sm text-red-600">Удалить запрос</button>
        </div>
    </div>
</div>
