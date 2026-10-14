<div class="space-y-3" @if($busy) wire:poll.3s @endif>
    @php $cur = $this->current; @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Закупки из 1С</h3>
            <span class="text-[12px] text-fg-3 ml-2">первая и последняя закупка по позициям — уходят в QuoteParts вместе с пулом</span>
        </div>
        <div class="ds-card-body text-[13px] space-y-3">
            <div class="flex flex-wrap gap-6">
                <div><span class="text-[20px] font-semibold mono">{{ number_format($cur['rows'], 0, ',', ' ') }}</span> <span class="text-fg-3">цен в базе</span></div>
                <div><span class="text-fg-3">самая свежая закупка:</span> <b>{{ $cur['newest'] ? \Illuminate\Support\Carbon::parse($cur['newest'])->format('d.m.Y') : '—' }}</b></div>
                <div><span class="text-fg-3">загружено:</span> <b>{{ $cur['loaded'] ? \Illuminate\Support\Carbon::parse($cur['loaded'])->format('d.m.Y H:i') : '—' }}</b></div>
            </div>

            <form wire:submit="upload" class="flex flex-wrap items-center gap-2">
                <input type="file" wire:model.live="file" accept=".xlsx,.xls" class="text-[12.5px]">
                {{-- Кнопка видна всегда: её состояние не должно зависеть от перерисовки после
                     загрузки файла (Livewire 4) и от wire:loading.attr, который возвращает
                     «состояние до загрузки». Нет файла — сообщит валидация. --}}
                @if($busy)
                    <span class="text-fg-4 text-[12px]">предыдущий файл ещё обрабатывается</span>
                @else
                    <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="file,upload">Загрузить и проверить</button>
                @endif
                <span wire:loading wire:target="file" class="text-fg-4 text-[12px]">файл загружается…</span>
                @error('file')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror
            </form>
            <div class="text-[11.5px] text-fg-4">
                Выгрузка 1С «Номенклатура (история цен)» в Excel: колонка A — «Артикул», затем первая закупка (поставщик, дата, цена, валюта) и последняя.
                Сначала файл проверяется без записи — вы увидите, сколько цен новых и изменилось, и подтверждаете запись.
            </div>
        </div>
    </div>

    <div class="ds-card">
        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="text-left px-3 py-2">Файл</th>
                        <th class="text-left px-3 py-2">Статус</th>
                        <th class="text-left px-3 py-2">Сводка</th>
                        <th class="text-left px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->imports as $imp)
                        @php $s = $imp->stats ?? []; @endphp
                        <tr wire:key="imp-{{ $imp->id }}" class="border-b border-border-subtle align-top">
                            <td class="px-3 py-2">
                                <div>{{ $imp->original_name }}</div>
                                <div class="text-[11px] text-fg-4">{{ $imp->created_at->format('d.m.Y H:i') }} · {{ $imp->user?->name }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <span class="chip {{ $imp->status === 'applied' ? 'chip-ok' : ($imp->status === 'failed' ? 'chip-danger' : ($imp->status === 'parsed' ? 'chip-warn' : 'chip-sky')) }} text-[10.5px]">{{ $imp->statusLabel() }}</span>
                                @if($imp->isBusy())<div class="text-[11px] text-fg-4 mt-1">обычно до минуты</div>@endif
                                @if($imp->error)<div class="text-[11.5px] text-red-700 mt-1 max-w-[320px]">{{ \Illuminate\Support\Str::limit($imp->error, 300) }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-[12px]">
                                @if($s)
                                    <div>Позиций в файле: <b>{{ $s['positions'] ?? 0 }}</b>, цен закупки: <b>{{ $s['points'] ?? 0 }}</b>, из них по нашему каталогу: <b>{{ $s['in_catalog'] ?? 0 }}</b></div>
                                    @isset($s['new'])
                                        <div>Новых: <b class="text-emerald-700">{{ $s['new'] }}</b>, изменилось: <b class="text-amber-700">{{ $s['changed'] }}</b>, без изменений: {{ $s['same'] }}</div>
                                    @endisset
                                    <div class="text-fg-4">Поставщик сопоставлен с реестром: {{ $s['supplier_matched'] ?? 0 }}</div>
                                    @if($imp->unmatched_suppliers)
                                        <details class="mt-1"><summary class="cursor-pointer text-fg-3">Не найдены в реестре поставщиков ({{ count($imp->unmatched_suppliers) }}+)</summary>
                                            <div class="text-fg-3 mt-1">@foreach($imp->unmatched_suppliers as $name => $n){{ $name }} ({{ $n }})@if(! $loop->last), @endif @endforeach</div>
                                        </details>
                                    @endif
                                    @if($imp->written)<div class="mt-1 text-emerald-700">Записано: {{ $imp->written }} · {{ $imp->applied_at?->format('d.m.Y H:i') }}</div>@endif
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                @if($imp->status === 'parsed')
                                    <button type="button" wire:click="apply({{ $imp->id }})" wire:confirm="Записать закупки из «{{ $imp->original_name }}»?" class="btn btn-sm btn-primary">Записать</button>
                                    <button type="button" wire:click="discard({{ $imp->id }})" class="btn btn-sm">Отменить</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-fg-3 text-[13px]">Загрузок через интерфейс ещё не было.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
