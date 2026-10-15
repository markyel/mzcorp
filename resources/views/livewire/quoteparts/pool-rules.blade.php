<div class="ds-card" @if($pending > 0) wire:poll.20s @endif>
    @php $inputCls = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp
    <div class="ds-card-header flex flex-wrap items-center gap-2">
        <h3>Подписки пула</h3>
        <span class="text-[12px] text-fg-3 ml-1">группа товаров + фильтры: подходящие позиции держатся в QuoteParts сами</span>
        @if($pending > 0)
            <span class="chip chip-sky text-[11px] ml-auto">передаётся {{ number_format($pending, 0, ',', ' ') }} из {{ number_format($inPool, 0, ',', ' ') }}</span>
        @endif
        <button type="button" wire:click="$toggle('formOpen')" class="btn btn-sm {{ $pending > 0 ? '' : 'ml-auto' }}">{{ $formOpen ? 'Отмена' : '+ Подписка' }}</button>
    </div>

    @if($formOpen)
        <form wire:submit="save" class="ds-card-body border-b border-border-subtle space-y-3 text-[13px]">
            <div class="flex flex-wrap items-end gap-3">
                <label class="block"><span class="text-fg-3 text-[12px]">Группа товаров</span><br>
                    <select wire:model.live="groupCode" class="{{ $inputCls }} pr-8">
                        @foreach($groups as $g)<option value="{{ $g->code }}">{{ $g->name }}</option>@endforeach
                        <option value="">все группы</option>
                    </select></label>
                <label class="block"><span class="text-fg-3 text-[12px]">Закупали в 1С за, лет</span><br>
                    <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="purchasedYears" placeholder="любые" class="{{ $inputCls }} w-24"></label>
                <label class="block"><span class="text-fg-3 text-[12px]">Спрашивали клиенты за, дней</span><br>
                    <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="requestedDays" placeholder="не важно" class="{{ $inputCls }} w-28"></label>
                <label class="inline-flex items-center gap-1.5 pb-1.5"><input type="checkbox" wire:model.live="inStock" class="w-4 h-4 accent-sky-600"> есть на складе</label>
                <label class="inline-flex items-center gap-1.5 pb-1.5"><input type="checkbox" wire:model.live="priceNotActual" class="w-4 h-4 accent-sky-600"> цена не актуальна</label>
                <label class="block"><span class="text-fg-3 text-[12px]">Бренды (через запятую)</span><br>
                    <input type="text" wire:model.live.debounce.500ms="brands" placeholder="любые" class="{{ $inputCls }} w-56"></label>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if($preview)
                    <span class="text-[13px]">Попадёт <b class="mono">{{ number_format($preview['matched'], 0, ',', ' ') }}</b> позиций, из них новых в пуле <b class="mono">{{ number_format($preview['new'], 0, ',', ' ') }}</b></span>
                @endif
                <input type="text" wire:model="name" placeholder="Название (необязательно)" class="{{ $inputCls }} w-64">
                <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="save">Сохранить и добавить в пул</button>
                @foreach(['purchasedYears', 'requestedDays', 'brands'] as $f)@error($f)<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror @endforeach
            </div>
            <p class="text-[12px] text-fg-3">Новые подходящие позиции добавляются сами — каждый час и после загрузки «Закупок 1С». Передача идёт в фоне порциями; поставщик видит позиции своих групп.</p>
        </form>
    @endif

    @if($rulesList->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr><th class="text-left px-3 py-2">Подписка</th><th class="text-left px-3 py-2">Условия</th><th class="text-right px-3 py-2">Подходит</th><th class="text-left px-3 py-2">Проверено</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($rulesList as $r)
                        <tr wire:key="pr-{{ $r->id }}" @class(['border-b border-border-subtle', 'opacity-60' => ! $r->is_active])>
                            <td class="px-3 py-1.5 text-fg-1">{{ $r->name }}</td>
                            <td class="px-3 py-1.5 text-fg-3">{{ $r->describe() }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ number_format($r->matched_count, 0, ',', ' ') }}</td>
                            <td class="px-3 py-1.5 text-fg-4">{{ $r->applied_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-right whitespace-nowrap">
                                <button type="button" wire:click="toggle({{ $r->id }})" class="btn btn-xs">{{ $r->is_active ? 'Выключить' : 'Включить' }}</button>
                                @if($r->is_active)<button type="button" wire:click="applyNow({{ $r->id }})" class="btn btn-xs">Проверить сейчас</button>@endif
                                <button type="button" wire:click="delete({{ $r->id }})" wire:confirm="Удалить подписку? Её позиции останутся в пуле и появятся в списке «к удалению»." class="text-fg-4 hover:text-red-700 text-[11.5px] ml-1">удалить</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($staleCount > 0)
        <div class="ds-card-body border-t border-border-subtle text-[12.5px] space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="chip chip-warn text-[11px]">к удалению {{ number_format($staleCount, 0, ',', ' ') }}</span>
                <span class="text-fg-3">пришли по подпискам, но больше не подходят ни к одной</span>
                <button type="button" wire:click="$toggle('staleOpen')" class="text-sky-700 hover:underline">{{ $staleOpen ? 'скрыть' : 'показать' }}</button>
                <button type="button" wire:click="removeStale" wire:confirm="Убрать эти позиции из QuoteParts? История там сохранится." class="btn btn-xs ml-auto">Убрать из QuoteParts</button>
            </div>
            @if($staleOpen)
                <div class="max-h-64 overflow-y-auto text-[12px] text-fg-2 columns-2">
                    @foreach($staleItems as $s)<div><span class="mono">{{ $s->sku }}</span> {{ \Illuminate\Support\Str::limit($s->catalogItem?->name, 60) }}</div>@endforeach
                    @if($staleCount > $staleItems->count())<div class="text-fg-4">… и ещё {{ $staleCount - $staleItems->count() }}</div>@endif
                </div>
            @endif
        </div>
    @endif
</div>
