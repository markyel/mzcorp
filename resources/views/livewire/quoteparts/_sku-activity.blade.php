{{-- Движение по позиции в QuoteParts под статусом: предложения (сколько, от какой цены), вопросы,
     отказы, когда последнее. Ссылки — в «Актуализацию цен» и «Вопросы поставщиков» по артикулу. Ждёт $act, $sku. --}}
@if(! empty($act))
    @php
        $plural = fn (int $n, string $one, string $few, string $many) => $n % 10 === 1 && $n % 100 !== 11 ? $one : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? $few : $many);
    @endphp
    <div style="display: flex; flex-direction: column; gap: 2px; margin-top: 4px; font-size: 11.5px; line-height: 1.35">
        @if($act['offers'] > 0)
            <a href="{{ route('quoteparts.index', ['tab' => 'offers', 'pq' => $sku, 'au' => 'all', 'pd' => 90]) }}" wire:navigate style="color: var(--emerald-700); text-decoration: none; white-space: nowrap">
                {{ $act['offers'] }} {{ $plural($act['offers'], 'предложение', 'предложения', 'предложений') }}@if($act['best']) · от {{ number_format($act['best']['price'], 2, ',', ' ') }} {{ $act['best']['currency'] }}@endif
            </a>
        @endif
        @if($act['questions'] > 0)
            <a href="{{ route('quoteparts.index', ['tab' => 'questions', 'q' => $sku, 'au' => 'all']) }}" wire:navigate style="color: var(--amber-700); text-decoration: none; white-space: nowrap">
                {{ $act['questions'] === 1 ? 'вопрос поставщика' : $act['questions'].' '.$plural($act['questions'], 'вопрос', 'вопроса', 'вопросов').' поставщиков' }} — ждёт ответа
            </a>
        @endif
        @if($act['declined'] > 0)
            <span style="color: var(--fg-3); white-space: nowrap">{{ $act['declined'] }} {{ $plural($act['declined'], 'отказ', 'отказа', 'отказов') }}</span>
        @endif
        @if($act['last_at'])
            <span style="color: var(--fg-4); white-space: nowrap">последнее {{ $act['last_at']->timezone(config('app.timezone'))->format('d.m H:i') }}</span>
        @endif
    </div>
@endif