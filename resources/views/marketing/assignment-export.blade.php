<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Поручение {{ $a->id }} — {{ $a->title }}</title>
    <style>
        body { font-family: {!! $font !!}; font-size: 12px; line-height: 1.5; color: #1f2937; margin: {{ $forPdf ? '0' : '32px auto' }}; max-width: {{ $forPdf ? 'none' : '780px' }}; }
        h1 { font-size: 19px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 18px 0 6px; }
        h3 { font-size: 13px; margin: 14px 0 4px; }
        p { margin: 0 0 8px; }
        ul, ol { margin: 0 0 8px; padding-left: 20px; }
        li { margin-bottom: 3px; }
        code { font-family: 'PT Mono', monospace; font-size: 11px; background: #f3f4f6; padding: 0 3px; }
        pre { font-family: 'PT Mono', monospace; font-size: 10.5px; background: #f3f4f6; padding: 8px; white-space: pre-wrap; }
        table { border-collapse: collapse; width: 100%; margin: 0 0 8px; }
        td, th { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
        .meta td { border: none; padding: 2px 8px 2px 0; }
        .meta td.k { color: #6b7280; width: 120px; }
        .muted { color: #6b7280; font-size: 11px; }
        .box { border: 1px solid #d1d5db; padding: 8px 10px; margin: 12px 0; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 14px 0; }
    </style>
</head>
<body>
    <div class="muted">Поручение № {{ $a->id }} · {{ now()->format('d.m.Y') }}</div>
    <h1>{{ $a->title }}</h1>

    <table class="meta">
        @if($a->executor)
            <tr><td class="k">Исполнитель</td><td>{{ $a->executor }}@if($a->executor_contact) · {{ $a->executor_contact }}@endif</td></tr>
        @endif
        @if($a->due_on)
            <tr><td class="k">Срок</td><td>{{ $a->due_on->format('d.m.Y') }}</td></tr>
        @endif
        <tr><td class="k">Приоритет</td><td>{{ \App\Models\MarketingAssignment::PRIORITIES[$a->priority] ?? 'обычный' }}</td></tr>
        @if($a->author)
            <tr><td class="k">Постановщик</td><td>{{ $a->author->name }}@if($a->author->email) · {{ $a->author->email }}@endif</td></tr>
        @endif
    </table>

    <hr>

    @if($body !== '')
        {!! $body !!}
    @else
        <p>{{ $a->brief }}</p>
    @endif

    @if($a->files->isNotEmpty())
        <div class="box">
            <b>Материалы</b> <span class="muted">(в архиве, папка «Материалы»)</span>
            <ol>
                @foreach($a->files as $i => $f)
                    <li>{{ sprintf('%02d', $i + 1) }}_{{ $f->filename }}@if($f->note) — {{ $f->note }}@endif</li>
                @endforeach
            </ol>
        </div>
    @endif

    <p class="muted">Вопросы по поручению — постановщику@if($a->author?->email) ({{ $a->author->email }})@endif.</p>
</body>
</html>
