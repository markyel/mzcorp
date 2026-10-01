@extends('news.layout')

@section('title', 'Обзор недели')
@section('description', config('services.marketing.news.feed_description'))

@section('content')
    <h1>Обзор недели</h1>
    <p class="intro">{{ config('services.marketing.news.feed_description') }} Лента для порталов и читалок: <a href="{{ route('news.rss') }}">RSS</a>.</p>
    <div class="list">
        @forelse($items as $p)
            <a class="row" href="{{ $p->newsUrl() }}">
                <b>{{ $p->title }}</b>
                <span>{{ $p->published_at?->locale('ru')->isoFormat('D MMMM YYYY') }}@if(! empty($p->article['period'])) · за {{ $p->article['period'] }}@endif</span>
            </a>
        @empty
            <p class="intro">Первый обзор выйдет в ближайшую пятницу.</p>
        @endforelse
    </div>
    {{ $items->links() }}
@endsection
