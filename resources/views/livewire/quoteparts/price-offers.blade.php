{{-- Вкладка «Актуализация цен»: свежие предложения поставщиков из QuoteParts — по заявкам, по позициям или списком;
     сверху — позиции, по которым предложений достаточно для актуализации цены (PriceRefreshReadiness). --}}
@php
    $rows = $this->rows;
    $readyItems = $this->readyItems;
@endphp
<div class="space-y-3">
    <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
        <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
            @foreach(\App\Livewire\QuoteParts\PriceOffers::VIEWS as $v => $label)
                <button type="button" wire:click="$set('view', '{{ $v }}')"
                        class="h-[28px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0 {{ $view === $v ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
            @foreach(\App\Livewire\QuoteParts\PriceOffers::DAYS as $d)
                <button type="button" wire:click="$set('days', {{ $d }})"
                        class="h-[28px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0 {{ $days === $d ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                    {{ $d }} дн.
                </button>
            @endforeach
        </div>
        <label class="inline-flex items-center gap-1.5 cursor-pointer text-fg-2" title="Предложения, заменённые новыми, и с истёкшим сроком действия">
            <input type="checkbox" wire:model.live="withStale" class="w-4 h-4 accent-sky-600"> и устаревшие
        </label>
        <span class="text-fg-4">Предложений: {{ $rows->count() }}{{ $rows->count() >= 500 ? ' (последние 500)' : '' }}@if($this->declines->isNotEmpty()) · отказов: {{ $this->declines->count() }}@endif{{ trim($search) !== '' ? ' по «'.trim($search).'»' : '' }}. ≈ ₽ — по курсу ЦБ. Позицию можно раскрыть — карточка каталога.</span>
    </div>

    {{-- Готово к актуализации: цену дали больше половины спрошенных или есть предложение и прошло больше 2 рабочих дней. --}}
    @if($readyItems->isNotEmpty())
        <div class="ds-card border-emerald-300">
            <div class="ds-card-header flex-wrap gap-y-1 bg-emerald-50">
                <h3 class="text-emerald-800">Готово к актуализации цены</h3>
                <span class="chip chip-ok text-[11px] ml-2">{{ $readyItems->count() }}</span>
                <span class="text-[12px] text-fg-3 ml-2">цену дали больше половины спрошенных поставщиков или есть хотя бы одно предложение и с запроса прошло больше {{ \App\Services\QuoteParts\PriceRefreshReadiness::WAIT_BUSINESS_DAYS }} рабочих дней</span>
            </div>
            @foreach($readyItems as $it)
                @include('livewire.quoteparts._po-item', ['it' => $it, 'ready' => true])
            @endforeach
        </div>
    @endif

    @if($view === 'item')
        @php $items = $this->items; @endphp
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>{{ $readyItems->isNotEmpty() ? 'Остальные позиции' : 'Позиции' }}</h3>
                <span class="text-[12px] text-fg-3 ml-2">{{ $items->count() }} · ждём предложений или без запроса (кабинет, прайс)</span>
            </div>
            @forelse($items as $it)
                @include('livewire.quoteparts._po-item', ['it' => $it, 'ready' => false])
            @empty
                <div class="ds-card-body text-center text-fg-3 text-[13px] py-6">За {{ $days }} дн. других предложений нет.</div>
            @endforelse
        </div>
    @else
        @forelse($this->groups as $g)
            <div class="ds-card" wire:key="pog-{{ $g['key'] }}">
                <div class="ds-card-header flex-wrap gap-y-1">
                    @if($g['request'])
                        <a href="{{ route('requests.show', $g['request']->id) }}" class="mono font-semibold text-sky-700 hover:underline">{{ $g['request']->internal_code }}</a>
                        <span class="text-[12px] text-fg-3 ml-2 truncate max-w-[520px]">{{ $g['request']->client_name ?: '' }}{{ $g['request']->client_name && $g['request']->subject ? ' · ' : '' }}{{ \Illuminate\Support\Str::limit((string) $g['request']->subject, 80) }}</span>
                        @if($g['request']->assignedUser)<span class="text-[12px] text-fg-4 ml-2">· {{ $g['request']->assignedUser->name }}</span>@endif
                    @else
                        <h3>Без заявки</h3>
                        <span class="text-[12px] text-fg-3 ml-2">кабинет, прайсы и запросы не по заявке</span>
                    @endif
                    <span class="ml-auto text-[11.5px] text-fg-3">
                        @foreach($g['rfqs'] as $tag)<span class="mono">{{ $tag }}</span>@if(! $loop->last), @endif @endforeach
                        · предложений {{ $g['rows']->count() }}@if($g['declines']->isNotEmpty()) · отказов {{ $g['declines']->count() }}@endif
                    </span>
                </div>
                @foreach($g['items'] as $it)
                    @include('livewire.quoteparts._po-item', ['it' => $it, 'ready' => false])
                @endforeach
            </div>
        @empty
            <div class="ds-card"><div class="ds-card-body text-center text-fg-3 text-[13px] py-8">
                За {{ $days }} дн. {{ $readyItems->isNotEmpty() ? 'других' : 'свежих' }} предложений нет. Они приходят из QuoteParts каждые 10 минут: ответы на запросы (форма, письмо), кабинет поставщика и прайсы.
            </div></div>
        @endforelse
    @endif
</div>
