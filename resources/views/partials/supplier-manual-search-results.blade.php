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
                {{-- Справа — карточка поставщика в новой вкладке (организация, иначе адрес из реестра): посмотреть, кто это. --}}
                <div x-data="{ addr: false }">
                <div style="display: flex; align-items: center;">
                <button type="button" wire:click="addSupplier({{ $r['representative_id'] }})" class="w-full text-left px-3 py-1.5 hover:bg-hover text-[12.5px]" style="flex: 1; min-width: 0;"
                        title="{{ $r['in_request'] ? 'Поставщик уже в списке выше — отметить его' : 'Добавить поставщика — по умолчанию на '.$r['email'].' (контакт «Расценка»)' }}">
                    {{ $r['name'] }}
                    @if($r['org_id'] === null)
                        @if($r['email'] !== $r['name'])<span class="text-fg-4 mono text-[11px]">{{ $r['email'] }}</span>@endif
                    @elseif($r['addresses'] > 1)
                        {{-- «адресов: N ▾» раскрывает все адреса с ролями; .stop — чтобы клик не добавлял поставщика. --}}
                        <span role="button" tabindex="0" x-on:click.stop="addr = ! addr" x-on:keydown.enter.stop.prevent="addr = ! addr"
                              title="Все адреса поставщика — выбрать конкретный ящик"
                              style="font-size: 11px; color: var(--sky-700); white-space: nowrap; cursor: pointer;">· адресов: {{ $r['addresses'] }} <span x-text="addr ? '▴' : '▾'">▾</span></span>
                    @endif
                    @if($r['in_request'])<span class="text-fg-4 text-[11px]">· уже в списке</span>@endif
                </button>
                <a href="{{ $r['org_id'] !== null ? route('suppliers.card', $r['org_id']) : route('suppliers.registry-edit', $r['representative_id']) }}"
                   target="_blank" rel="noopener" title="Карточка поставщика — в новой вкладке"
                   style="flex: none; padding: 4px 10px; font-size: 11.5px; color: var(--sky-700); white-space: nowrap; text-decoration: none;">карточка ↗</a>
                </div>
                @if(count($r['all_emails'] ?? []) > 1)
                    <div x-show="addr" x-cloak style="background: var(--bg-surface-2);">
                        @foreach($r['all_emails'] as $e)
                            <button type="button" wire:click="addSupplier({{ $e['id'] }})" class="w-full text-left hover:bg-hover text-[11.5px] text-fg-3" style="padding: 3px 12px 3px 24px"
                                    title="{{ $e['in_request'] ? 'Адрес уже в списке выше — отметить его' : 'Отправить именно на этот адрес' }}">
                                <span class="mono" style="color: var(--fg-1)">{{ $e['email'] }}</span>
                                @if($e['topics'] !== [])<span class="text-fg-4 text-[11px]"> · {{ implode(', ', $e['topics']) }}</span>@endif
                                @if($e['id'] === $r['representative_id'])<span class="text-[11px]" style="color: var(--status-ok-fg, #047857); font-weight: 600;"> · по умолчанию</span>@endif
                                @if($e['in_request'])<span class="text-fg-4 text-[11px]"> · уже в списке</span>@endif
                            </button>
                        @endforeach
                    </div>
                @endif
                </div>
                @foreach($r['emails'] as $e)
                    <button type="button" wire:click="addSupplier({{ $e['id'] }})" class="w-full text-left hover:bg-hover text-[11.5px] text-fg-3" style="padding: 2px 12px 4px 24px"
                            title="{{ $e['in_request'] ? 'Адрес уже в списке выше — отметить его' : 'Отправить именно на этот адрес' }}">
                        ↳ только на <span class="mono">{{ $e['email'] }}</span>
                        @if($e['id'] === $r['representative_id'])<span class="text-[11px]" style="color: var(--status-ok-fg, #047857); font-weight: 600;"> · по умолчанию</span>@endif
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
