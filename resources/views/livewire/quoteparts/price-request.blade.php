{{-- «Запросить цены» раздела «QuoteParts»: панель «Запрос поставщикам» из «Снабжения», без выбора канала. --}}
<div>
    @include('livewire.procurement._supplier-request-panel', ['panelAddsItems' => true, 'panelChannelChoice' => false])
</div>
