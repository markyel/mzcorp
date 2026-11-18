{{--
    Результаты ручного поиска поставщика (заявка → «Поставщики», «Снабжение»): одна строка на поставщика.
    Клик по строке — в запрос встаёт представитель, кому именно отправить, выберет QuoteParts.
    Под строкой — адреса, совпавшие с поиском по e-mail: выбрать конкретный ящик.
    Поставщик или адрес, уже стоящий в списке, помечен «уже в списке»: клик его отмечает.
    Сразу видны первые SupplierManualSearch::LIMIT; остальные — «ещё N — показать всех» (до MAX), со своей прокруткой
    (18.11.2026: по «unisystem» нужны все «… через UniSystem», а видно было 8).
    Ожидает $results (SupplierManualSearch::search) и Livewire-метод addSupplier(id).
--}}
@if($results !== [])
    @php
        $limit = \App\Services\Supplier\SupplierManualSearch::LIMIT;
        $more = max(0, count($results) - $limit);
        $capped = count($results) >= \App\Services\Supplier\SupplierManualSearch::MAX;
    @endphp
    <div x-data="{ all: false }" wire:key="sup-search-list-{{ md5(implode(',', array_column($results, 'key'))) }}"
         class="mt-1 border border-border rounded-md divide-y divide-border-subtle" style="max-width: 420px"
         x-bind:style="all ? { maxHeight: '360px', overflowY: 'auto' } : {}">
        @foreach($results as $i => $r)
            <div wire:key="sup-search-{{ $r['key'] }}" @if($i >= $limit) x-show="all" x-cloak @endif>
                <button type="button" wire:click="addSupplier({{ $r['representative_id'] }})" class="w-full text-left px-3 py-1.5 hover:bg-hover text-[12.5px]"
                        title="{{ $r['in_request'] ? 'Поставщик уже в списке выше — отметить его' : 'Добавить поставщика: кому именно отправить, выберет QuoteParts (контакт «Расценка»)' }}">
                    {{ $r['name'] }}
                    @if($r['org_id'] === null)
                        @if($r['email'] !== $r['name'])<span class="text-fg-4 mono text-[11px]">{{ $r['email'] }}</span>@endif
                    @elseif($r['addresses'] > 1)
                        <span class="text-fg-4 text-[11px]">· адресов: {{ $r['addresses'] }}</span>
                    @endif
                    @if($r['in_request'])<span class="text-fg-4 text-[11px]">· уже в списке</span>@endif
                </button>
                @foreach($r['emails'] as $e)
                    <button type="button" wire:click="addSupplier({{ $e['id'] }})" class="w-full text-left hover:bg-hover text-[11.5px] text-fg-3" style="padding: 2px 12px 4px 24px"
                            title="{{ $e['in_request'] ? 'Адрес уже в списке выше — отметить его' : 'Отправить именно на этот адрес' }}">
                        ↳ только на <span class="mono">{{ $e['email'] }}</span>
                        @if($e['in_request'])<span class="text-fg-4 text-[11px]">· уже в списке</span>@endif
                    </button>
                @endforeach
            </div>
        @endforeach
        @if($more > 0)
            <button type="button" x-show="! all" x-on:click="all = true" class="w-full text-left px-3 py-1.5 hover:bg-hover text-[12px]" style="color: var(--sky-700)">
                ещё {{ $more }} — показать всех</button>
            @if($capped)
                <div x-show="all" x-cloak class="px-3 py-1.5 text-[11.5px] text-fg-4">Показаны первые {{ count($results) }} — уточните поиск, чтобы найти остальных.</div>
            @endif
        @endif
    </div>
@endif
