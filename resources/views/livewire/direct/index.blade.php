@php
    $inp = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $queue = $this->queue;
@endphp

<div class="space-y-4">

    @if($notice)
        <div class="ds-card"><div class="ds-card-body flex items-center gap-2 text-[13px] text-emerald-700">
            <span>{{ $notice }}</span><span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="dismiss">Скрыть</button>
        </div></div>
    @endif
    @if($error)
        <div class="ds-card"><div class="ds-card-body flex items-center gap-2 text-[13px] text-amber-800">
            <span>{{ $error }}</span><span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="dismiss">Скрыть</button>
        </div></div>
    @endif

    {{-- Связь с Директом --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🔌 Связь с Яндекс.Директом</h3>
            <span class="chip text-[10.5px]"
                  style="background:{{ $hasToken ? 'var(--emerald-50)' : 'var(--red-50)' }};color:{{ $hasToken ? 'var(--emerald-700)' : 'var(--red-700)' }}">
                <span class="dot"></span>{{ $hasToken ? 'токен есть' : 'токена нет' }}
            </span>
            @if($sandbox)
                <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">режим песочницы</span>
            @else
                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">боевой контур</span>
            @endif
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                <span wire:loading.remove wire:target="checkConnection">Проверить связь</span>
                <span wire:loading wire:target="checkConnection">Проверяю…</span>
            </button>
        </div>
        <div class="ds-card-body space-y-2">
            <div class="text-[12px] text-fg-3">
                Эндпоинт: <span class="mono text-fg-2">{{ $endpoint }}</span>
                @unless($feedToken)
                    · <span class="text-amber-700">фид не настроен: пуст YANDEX_DIRECT_FEED_TOKEN</span>
                @endunless
            </div>

            @if($check)
                @if($check['units'])
                    <div class="text-[12.5px] text-fg-2">
                        Баллы API: потрачено на запрос <b class="mono">{{ $check['units']['spent'] }}</b>,
                        осталось на сутки <b class="mono">{{ $money($check['units']['rest']) }}</b>
                        из <span class="mono">{{ $money($check['units']['limit']) }}</span>
                        <span class="text-fg-4">· проверено в {{ $check['at'] }}</span>
                    </div>
                @endif
                <div>
                    <div class="text-[12px] text-fg-3 mb-1">Кампании аккаунта: {{ count($check['campaigns']) }}</div>
                    @foreach($check['campaigns'] as $c)
                        <div class="flex items-center gap-2 text-[12.5px] py-1 border-t border-border-subtle">
                            <span class="mono text-fg-4">#{{ $c['id'] }}</span>
                            <span class="text-fg-1">{{ $c['name'] }}</span>
                            <span class="chip text-[10.5px]"
                                  style="background:{{ $c['state'] === 'ON' ? 'var(--emerald-50)' : 'var(--neutral-100)' }};color:{{ $c['state'] === 'ON' ? 'var(--emerald-700)' : 'var(--fg-3)' }}">
                                {{ $c['state'] === 'ON' ? 'идёт показ' : 'выключена' }}
                            </span>
                            <span class="text-[11.5px] text-fg-4">{{ $c['status'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-[12.5px] text-fg-3">Нажмите «Проверить связь» — запрос покажет остаток баллов и кампании аккаунта.</div>
            @endif
        </div>
    </div>

    {{-- Сколько объявлений держим --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🎚 Сколько объявлений держим</h3>
            <span class="text-[12px] text-fg-3">верхние позиции очереди уходят в Директ, остальные ждут</span>
        </div>
        <div class="ds-card-body">
            <form wire:submit.prevent="saveLimit" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Объявлений одновременно</label>
                    <input type="number" wire:model="adsLimit" min="{{ \App\Livewire\Direct\Index::MIN_ADS_LIMIT }}"
                           max="{{ \App\Livewire\Direct\Index::MAX_ADS_LIMIT }}" class="{{ $inp }} w-[120px] mono">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                <span class="flex-1"></span>
                <div class="text-[12.5px] text-fg-2">
                    Годных к показу позиций: <b class="mono">{{ $money($this->readyCount) }}</b>
                    <span class="text-fg-4">— остаток на складе и актуальная цена</span>
                </div>
            </form>
            <div class="mt-2 text-[11.5px] text-fg-4">
                Рекламируем только то, на что можем сразу дать цену: по такой заявке с артикулом
                КП уходит автоматически. Начинаем с малого числа и расширяемся по мере окупаемости.
            </div>
        </div>
    </div>

    {{-- Очередь позиций --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📋 Очередь позиций</h3>
            <span class="text-[12px] text-fg-3">по деньгам за 12 месяцев</span>
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="refreshQueue">↻ Пересобрать</button>
        </div>
        <div class="ds-card-body">
            @if($queue->isEmpty())
                <div class="text-[13px] text-fg-3">Пусто: нет позиций с остатком и актуальной ценой.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]" style="border-collapse:collapse">
                        <thead>
                            <tr class="text-fg-3 text-[11px] uppercase tracking-wide">
                                <th class="text-left py-1.5 pr-2">#</th>
                                <th class="text-left py-1.5 pr-2">Артикул</th>
                                <th class="text-left py-1.5 pr-2">Позиция</th>
                                <th class="text-right py-1.5 pr-2">Цена</th>
                                <th class="text-right py-1.5 pr-2">Остаток</th>
                                <th class="text-right py-1.5 pr-2">Заявок</th>
                                <th class="text-right py-1.5 pr-2">Оплачено</th>
                                <th class="text-left py-1.5 pr-2">Коды</th>
                                <th class="py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($queue as $i => $row)
                                @php
                                    $inWork = $i < $adsLimit;
                                    $codes = \App\Services\Catalog\YandexDirectFeedService::codes($row);
                                @endphp
                                <tr wire:key="dq-{{ $row->sku }}" class="border-t border-border-subtle {{ $inWork ? '' : 'opacity-55' }}">
                                    <td class="py-1.5 pr-2 mono text-fg-4">{{ $i + 1 }}</td>
                                    <td class="py-1.5 pr-2 mono {{ $inWork ? 'text-fg-1' : 'text-fg-3' }}">{{ $row->sku }}</td>
                                    <td class="py-1.5 pr-2">
                                        <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($row->name, 54) }}</div>
                                        @if($row->brand)<div class="text-[11px] text-fg-4">{{ $row->brand }}</div>@endif
                                    </td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ $money($row->price) }} ₽</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ (int) $row->stock_available }}</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ (int) $row->reqs }}</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ $money($row->paid) }} ₽</td>
                                    <td class="py-1.5 pr-2 text-[11.5px] text-fg-3">
                                        {{ $codes ? \Illuminate\Support\Str::limit(implode(', ', $codes), 30) : '—' }}
                                    </td>
                                    <td class="py-1.5 text-right">
                                        {{-- Причину спрашиваем сразу: через месяц «почему эта позиция снята»
                                             по одному артикулу уже не восстановить. --}}
                                        <button type="button" class="btn btn-sm"
                                                x-data
                                                @click="$wire.excludeItem('{{ $row->sku }}', window.prompt('Почему убираем {{ $row->sku }} из рекламы? (можно пусто)', '') ?? '')"
                                                title="Убрать позицию из рекламы — и из очереди, и из фида">✕ Не рекламировать</button>
                                    </td>
                                </tr>
                                @if($inWork && $i + 1 === $adsLimit)
                                    <tr wire:key="dq-line">
                                        <td colspan="9" class="py-1">
                                            <div class="flex items-center gap-2 text-[11px] text-fg-4">
                                                <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                                                <span>граница: выше — в работе, ниже — в очереди</span>
                                                <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Снятые с рекламы вручную --}}
    @php $excluded = $this->excluded; @endphp
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🚫 Не рекламируем</h3>
            <span class="text-[12px] text-fg-3">сняты вручную — не попадают ни в очередь, ни в фид</span>
            <span class="flex-1"></span>
            <span class="mono text-[12px] text-fg-4">{{ $excluded->count() }}</span>
        </div>
        <div class="ds-card-body">
            @forelse($excluded as $ex)
                <div wire:key="dx-{{ $ex->id }}"
                     class="flex flex-wrap items-center gap-2 py-2 {{ ! $loop->last ? 'border-b border-border-subtle' : '' }}">
                    <span class="mono text-[12.5px] text-fg-1">{{ $ex->sku }}</span>
                    <span class="text-[12.5px] text-fg-2">
                        {{ \Illuminate\Support\Str::limit($ex->catalogItem?->name ?? '—', 52) }}
                    </span>
                    @if($ex->reason)
                        <span class="text-[12px] text-fg-3">· {{ \Illuminate\Support\Str::limit($ex->reason, 60) }}</span>
                    @endif
                    <span class="flex-1"></span>
                    <span class="text-[11px] text-fg-4">
                        {{ $ex->excludedBy?->name ?? 'система' }} · {{ $ex->created_at?->format('d.m.Y') }}
                    </span>
                    <button type="button" class="btn btn-sm" wire:click="restoreItem('{{ $ex->sku }}')"
                            title="Вернуть позицию в очередь">↩ Вернуть</button>
                </div>
            @empty
                <div class="text-[13px] text-fg-3">
                    Пока никого. Сюда попадают позиции, которые проходят по складу и цене, но сами по себе
                    спросом не пользуются — комплектующие к другому товару, расходники, упаковка.
                </div>
            @endforelse
        </div>
    </div>
</div>
