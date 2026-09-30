<div>
    @if($open && ($leaver = $this->leaver))
        @php $p = $this->preview; $plan = $p['plan']; $del = $p['delegations']; @endphp
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[600px] max-h-[90vh] overflow-y-auto" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">Отключить менеджера</h3>
                <div class="text-[12.5px] text-fg-3 mb-3">
                    «{{ $leaver->name }}» уходит насовсем: его незакрытые заявки перейдут другим менеджерам,
                    вход в систему и личные почтовые ящики отключатся. Для отпуска и больничного — «⏸ Недоступен…».
                </div>

                <div class="text-[12.5px] text-fg-2 mb-3 flex flex-wrap gap-x-4 gap-y-1">
                    <span>Незакрытых заявок: <b class="mono text-fg-1">{{ $p['open'] }}</b></span>
                    @if($plan)<span>клиентов: <b class="mono text-fg-1">{{ $plan['clients'] }}</b></span>@endif
                    @if($del['as_original'] + $del['as_acting'] > 0)
                        <span>делегирований закроется: <b class="mono text-fg-1">{{ $del['as_original'] + $del['as_acting'] }}</b></span>
                    @endif
                </div>

                <div class="space-y-2 mb-3">
                    <label class="flex items-start gap-2 text-[12.5px] cursor-pointer">
                        <input type="radio" wire:model.live="mode" value="spread" class="mt-0.5">
                        <span class="text-fg-1">Раздать между доступными менеджерами
                            <span class="block text-fg-3 text-[11.5px]">Заявки одного клиента уйдут одному менеджеру. Учитываются вес в распределении, текущая нагрузка и потолок сложности.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 text-[12.5px] cursor-pointer">
                        <input type="radio" wire:model.live="mode" value="one" class="mt-0.5">
                        <span class="text-fg-1">Передать все заявки одному менеджеру</span>
                    </label>
                    @if($mode === 'one')
                        <select wire:model.live="heirId"
                                class="ml-6 w-[calc(100%-1.5rem)] h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                            <option value="">— выберите менеджера —</option>
                            @foreach($p['candidates'] as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                @if($plan && $plan['by_heir'] !== [])
                    <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Кому уйдут заявки</div>
                    <table class="w-full text-[12.5px] mb-3">
                        @foreach($plan['by_heir'] as $row)
                            <tr class="border-t border-border-subtle">
                                <td class="py-1 pr-2 text-fg-1">{{ $row['user']->name }}</td>
                                <td class="py-1 px-2 text-right mono tnum">{{ $row['requests'] }} заяв.</td>
                                <td class="py-1 pl-2 text-right mono tnum text-fg-3">{{ $row['clients'] }} клиент.</td>
                            </tr>
                        @endforeach
                    </table>
                @elseif($plan && $plan['requests'] === 0)
                    <p class="text-[12.5px] text-fg-3 mb-3">Незакрытых заявок нет — останется только отключить учётку.</p>
                @endif

                <div class="mb-3">
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Комментарий (попадёт в историю заявок)</label>
                    <input type="text" wire:model="comment" maxlength="150" placeholder="Например: уволился 30.09"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                </div>

                @error('heirId') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="close" class="btn btn-sm">Отмена</button>
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                            wire:confirm="Отключить «{{ $leaver->name }}» и передать его заявки? Отменить автоматически нельзя: вернуть заявки можно только переподчинением вручную."
                            class="btn btn-sm btn-danger">
                        <span wire:loading.remove wire:target="confirm">Отключить и передать заявки</span>
                        <span wire:loading wire:target="confirm">Передаю заявки…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
