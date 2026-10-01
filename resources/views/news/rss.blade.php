{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title>{{ config('services.marketing.news.feed_title') }}</title>
    <link>{{ route('news.index') }}</link>
    <description>{{ config('services.marketing.news.feed_description') }}</description>
    <language>ru</language>
    <atom:link href="{{ route('news.rss') }}" rel="self" type="application/rss+xml"/>
@foreach($items as $item)
    <item>
        <title>{{ $item['title'] }}</title>
        <link>{{ $item['link'] }}</link>
        <guid isPermaLink="true">{{ $item['link'] }}</guid>
        @if($item['pubDate'])<pubDate>{{ $item['pubDate'] }}</pubDate>@endif
        <author>{{ config('services.marketing.news.email') }} ({{ config('services.marketing.news.brand') }})</author>
        <description>{{ $item['description'] }}</description>
        <content:encoded><![CDATA[{!! str_replace(']]>', ']]]]><![CDATA[>', $item['html']) !!}]]></content:encoded>
        @if($item['cover'])<enclosure url="{{ $item['cover'] }}" type="image/jpeg" length="0"/>@endif
    </item>
@endforeach
</channel>
</rss>
