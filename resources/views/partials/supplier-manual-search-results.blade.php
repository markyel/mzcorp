{{--
    Результаты ручного поиска поставщика (заявка → «Поставщики», «Снабжение»): одна строка на поставщика.
    Клик по строке — в запрос встаёт представитель, кому именно отправить, выберет QuoteParts.
    Под строкой — адреса, совпавшие с поиском по e-mail: выбрать конкретный ящик.
    Ожидает $results (SupplierManualSearch::search) и Livewire-метод addSupplier(id).
--}}
@if($results !== [])
    <div class="mt-1 border border-border rounded-md divide-y divide-border-subtle" style="max-width: 420px">
        @foreach($results as $r)
            <div wire:key="sup-search-{{ $r['key'] }}">
                <button type="button" wire:click="addSupplier({{ $r['representative_id'] }})" class="w-full text-left px-3 py-1.5 hover:bg-hover text-[12.5px]"
                        title="Добавить поставщика: кому именно отправить, выберет QuoteParts (контакт «Расценка»)">
                    {{ $r['name'] }}
                    @if($r['org_id'] === null)
                        @if($r['email'] !== $r['name'])<span class="text-fg-4 mono text-[11px]">{{ $r['email'] }}</span>@endif
                    @elseif($r['addresses'] > 1)
                        <span class="text-fg-4 text-[11px]">· адресов: {{ $r['addresses'] }}</span>
                    @endif
                </button>
                @foreach($r['emails'] as $e)
                    <button type="button" wire:click="addSupplier({{ $e['id'] }})" class="w-full text-left hover:bg-hover text-[11.5px] text-fg-3" style="padding: 2px 12px 4px 24px"
                            title="Отправить именно на этот адрес">
                        ↳ только на <span class="mono">{{ $e['email'] }}</span>
                    </button>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
