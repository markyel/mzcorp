<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm 16mm 18mm 16mm; }
        * { box-sizing: border-box; }
        body {
            font-family: 'PT Sans', sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #1a1a1a;
        }
        .doc-head {
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 8px;
            margin-bottom: 14px;
        }
        .doc-title { font-size: 17px; font-weight: bold; margin: 0 0 4px; }
        .doc-meta { font-size: 10.5px; color: #555; }
        .doc-meta span { margin-right: 12px; }

        .msg {
            border: 1px solid #d8d8d8;
            border-radius: 5px;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .msg-head {
            padding: 6px 10px;
            border-bottom: 1px solid #e6e6e6;
            background: #f6f6f6;
        }
        .msg-head.out { background: #eef3fb; }
        .msg-author { font-weight: bold; font-size: 12.5px; }
        .badge {
            display: inline-block;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 1px 6px;
            border-radius: 8px;
            vertical-align: middle;
            margin-left: 6px;
        }
        .badge.in { background: #e4e4e4; color: #444; }
        .badge.out { background: #2f5bbf; color: #fff; }
        .badge.cat { background: #d7efe0; color: #1f6b43; }
        .msg-sub { font-size: 10px; color: #666; margin-top: 2px; font-family: 'PT Mono', monospace; }
        .msg-body { padding: 9px 11px; font-size: 11.5px; word-wrap: break-word; }
        /* Форсируем PT Sans: иначе font-family из подписи письма
           (Helvetica/Arial) уводит dompdf на Type1-шрифт без кириллицы → «???». */
        .msg-body, .msg-body * { font-family: 'PT Sans', sans-serif !important; }
        .msg-body img { max-width: 100%; height: auto; }
        .msg-body table { max-width: 100%; }

        .photos { padding: 0 11px 10px; }
        .photos .ph { display: inline-block; margin: 4px 6px 0 0; vertical-align: top; }
        .photos img { max-width: 240px; max-height: 240px; border: 1px solid #ddd; border-radius: 4px; }
        .photos .cap { font-size: 9px; color: #777; max-width: 240px; }

        .files {
            padding: 7px 11px;
            border-top: 1px dashed #d8d8d8;
            font-size: 10.5px;
            color: #555;
        }
        .files .lbl { font-weight: bold; color: #333; }
        .files .file { display: block; margin-top: 2px; }
        .files .file .ic {
            display: inline-block; font-weight: bold; color: #b3402a;
            font-size: 9px; margin-right: 4px;
        }

        .empty { color: #999; font-style: italic; }
    </style>
</head>
<body>
    <div class="doc-head">
        <div class="doc-title">Переписка по заявке {{ $request->internal_code }}</div>
        <div class="doc-meta">
            @if($request->client_name)<span>Клиент: {{ $request->client_name }}</span>@endif
            @if($request->client_email)<span>{{ $request->client_email }}</span>@endif
            <span>Писем: {{ count($messages) }}</span>
            <span>Сформировано: {{ $generatedAt }}</span>
        </div>
    </div>

    @forelse($messages as $msg)
        <div class="msg">
            <div class="msg-head {{ $msg['outbound'] ? 'out' : '' }}">
                <span class="msg-author">{{ $msg['author'] }}</span>
                @if($msg['outbound'])
                    <span class="badge out">Исходящее</span>
                @else
                    <span class="badge in">Входящее</span>
                @endif
                @if($msg['category'])
                    <span class="badge cat">{{ $msg['category'] }}</span>
                @endif
                <div class="msg-sub">
                    {{ $msg['from_email'] }} · {{ $msg['sent_at'] ?? '—' }}@if($msg['mailbox']) · через {{ $msg['mailbox'] }}@endif
                </div>
            </div>

            <div class="msg-body">
                @if($msg['body'])
                    {!! $msg['body'] !!}
                @else
                    <span class="empty">(пустое тело письма)</span>
                @endif
            </div>

            @if(! empty($msg['images']))
                <div class="photos">
                    @foreach($msg['images'] as $img)
                        <div class="ph">
                            <img src="{{ $img['data'] }}" alt="{{ $img['name'] }}">
                            <div class="cap">{{ $img['name'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if(! empty($msg['files']))
                <div class="files">
                    <span class="lbl">Вложения (в архиве):</span>
                    @foreach($msg['files'] as $f)
                        <span class="file">
                            <span class="ic">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($f['name'], '.')) ?: 'FILE' }}</span>{{ $f['name'] }}@if($f['kb']) <span style="color:#999"> · {{ $f['kb'] }} КБ</span>@endif
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div class="empty">Переписка пуста.</div>
    @endforelse
</body>
</html>
