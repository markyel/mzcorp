@extends('news.layout')

@section('title', $publication->title)
@section('description', \Illuminate\Support\Str::limit((string) ($article['lead'] ?? ''), 200))
@section('meta')
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $publication->title }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit((string) ($article['lead'] ?? ''), 200) }}">
    @if(! empty($article['cover']))<meta property="og:image" content="{{ $article['cover'] }}">@endif
    <link rel="canonical" href="{{ $publication->newsUrl() }}">
    @if($preview)<meta name="robots" content="noindex">@endif
@endsection

@section('content')
    @if($preview)
        <div class="preview">Предпросмотр черновика — страницу видят только сотрудники. После публикации она появится в ленте и RSS.</div>
    @endif
    <article>
        <h1>{{ $publication->title }}</h1>
        <div class="meta">
            {{ ($publication->published_at ?? $publication->created_at)->locale('ru')->isoFormat('D MMMM YYYY') }}
            @if(! empty($article['period'])) · за {{ $article['period'] }}@endif
        </div>
        <p class="lead">{{ $article['lead'] }}</p>

        @foreach($article['sections'] as $section)
            <h2>{{ $section['heading'] }}</h2>
            @if($section['intro'] !== '')<p class="intro">{{ $section['intro'] }}</p>@endif
            <div class="items">
                @foreach($section['items'] as $it)
                    <div class="item">
                        <a href="{{ $it['url'] }}">@if(! empty($it['photo']))<img src="{{ $it['photo'] }}" alt="{{ $it['name'] }}" loading="lazy">@endif</a>
                        <div>
                            <a class="name" href="{{ $it['url'] }}">{{ $it['name'] }}</a>
                            <div class="sku">арт. {{ $it['sku'] }}</div>
                            @php $drop = $section['kind'] === 'price' ? \App\Services\Marketing\WeeklyRoundupService::priceDrop($it) : null; @endphp
                            @if($drop !== null)<div class="price">{{ $drop }}</div>@endif
                            <span class="stock {{ $it['in_stock'] ? '' : 'order' }}">{{ $it['in_stock'] ? 'есть на складе' : 'под заказ' }}</span>
                            @if($it['note'] !== '')<p class="note">{{ $it['note'] }}</p>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        @if(($article['closing'] ?? '') !== '')
            <p class="closing">{{ $article['closing'] }}</p>
        @endif
    </article>
@endsection
