{{-- Карточка заявки → «Поставщики»: предложения поставщиков по позициям заявки — тот же блок позиции, что в «Актуализации
     цен» (_po-item: «Закупаем здесь» / «Уточнить» / «Игнорировать», комментарий для 1С, последняя закупка, переписка под
     строкой). Выгрузка в 1С — нижней полосой заявки, поэтому «+ В выгрузку» в шапке позиции здесь не показывается. --}}
@php
    $cardItems = $this->cardItems;
@endphp
<div class="pa" style="gap: 0">
    @include('livewire.quoteparts._po-styles')
    @if($cardItems->isNotEmpty())
        <div style="overflow-x: auto">
            <div style="min-width: 860px">
                <div class="pa-grid pa-colhead">
                    <span>Поставщик · ответ</span><span style="text-align: right">Цена</span><span>Условия</span><span>Комментарий для 1С</span><span style="text-align: right">Решение</span>
                </div>
                @foreach($cardItems as $it)
                    @include('livewire.quoteparts._po-item', ['it' => $it, 'section' => $this->cardSection($it)])
                @endforeach
            </div>
        </div>
    @endif
</div>
