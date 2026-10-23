{{-- Сообщение ленты переписки с поставщиком (вкладка «Поставщики» заявки, «Актуализация цен»): как в чате —
     поставщик слева с аватаром и серым пузырём, мы справа в белом пузыре с рамкой, события — строкой по центру.
     Ждёт $msg: dir in|out|event, name, who?, subject?, gist, body?, at, files? (url, dl?, name, mime?, image). --}}
@once
    <style>
        .ct-ev{display:flex;align-items:center;gap:12px;margin:10px 0;font-size:12px;color:var(--fg-3);text-align:center}
        .ct-ev::before,.ct-ev::after{content:'';flex:1;height:1px;background:var(--border)}
        .ct-in{display:grid;grid-template-columns:32px minmax(0,1fr);gap:10px;max-width:68%;margin:12px 0}
        .ct-av{width:32px;height:32px;border-radius:999px;background:var(--bg-surface-2);color:var(--fg-2);font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;text-transform:uppercase}
        .ct-out{display:flex;flex-direction:column;align-items:flex-end;margin:12px 0 12px auto;max-width:68%}
        .ct-head{display:flex;align-items:baseline;gap:8px;margin-bottom:4px;font-size:12.5px;color:var(--fg-3);font-variant-numeric:tabular-nums}
        .ct-head b{color:var(--fg-1);font-weight:600}
        .ct-bubble{display:flex;flex-direction:column;gap:4px;border-radius:10px;padding:10px 14px;font-size:13px;line-height:1.45;color:var(--fg-1);text-wrap:pretty;min-width:0;overflow-wrap:anywhere}
        .ct-in .ct-bubble{background:var(--bg-surface-2)}
        .ct-out .ct-bubble{background:var(--bg-surface);border:1px solid var(--border)}
        .ct-label{font-size:12px;color:var(--fg-2)}
        .ct-more{color:var(--sky-700);font-size:12px}
        .ct-files{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px}
        .ct-thumb{display:block;width:56px;height:56px;border:1px solid var(--border);border-radius:6px;overflow:hidden;cursor:zoom-in;background:var(--bg-surface)}
        .ct-thumb img{width:100%;height:100%;object-fit:cover;display:block}
        @media (max-width:640px){.ct-in,.ct-out{max-width:100%}}
    </style>
@endonce
@php
    $dir = $msg['dir'] ?? 'event';
    $body = (string) ($msg['body'] ?? '');
    $gist = (string) ($msg['gist'] ?? '');
    $more = $dir !== 'event' && $body !== '' && trim(preg_replace('/\s+/u', ' ', $body) ?? '') !== $gist;
    $files = collect($msg['files'] ?? []);
    $gallery = $files->where('image', true)->values()->map(fn ($f) => ['src' => $f['url'], 'name' => $f['name'], 'dl' => $f['dl'] ?? $f['url']])->all();
    $shot = 0;
@endphp
@if($dir === 'event')
    <div class="ct-ev"><span>{{ $gist }}@if(! empty($msg['at'])) · {{ $msg['at'] }}@endif</span></div>
@else
    <div class="{{ $dir === 'in' ? 'ct-in' : 'ct-out' }}" x-data="{ full: false }">
        @if($dir === 'in')
            <span class="ct-av">{{ mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', (string) $msg['name']) ?: '?', 0, 2) }}</span>
        @endif
        <div style="min-width: 0; {{ $dir === 'out' ? 'display: flex; flex-direction: column; align-items: flex-end' : '' }}">
            <div class="ct-head">
                @if($dir === 'in')
                    <b>{{ $msg['name'] }}</b>@if(! empty($msg['who']))<span>{{ $msg['who'] }}</span>@endif<span>{{ $msg['at'] }}</span>
                @else
                    <span>{{ $msg['at'] }}</span><b>{{ $msg['name'] }}@if(! empty($msg['who'])) · {{ $msg['who'] }}@endif</b>
                @endif
            </div>
            <div class="ct-bubble">
                @if(! empty($msg['error']))<span style="font-size: 12px; color: var(--red-700)">не отправлено: {{ $msg['error'] }}</span>@endif
                @if(! empty($msg['subject']))<span class="ct-label">{{ $msg['subject'] }}</span>@endif
                @if($more)
                    <span x-show="! full">{{ $gist }} <button type="button" x-on:click="full = true" class="ct-more">весь текст</button></span>
                    <span x-show="full" x-cloak style="white-space: pre-wrap; max-height: 420px; overflow-y: auto">{{ $body }}</span>
                    <button type="button" x-show="full" x-cloak x-on:click="full = false" class="ct-more" style="align-self: flex-start">свернуть</button>
                @elseif($gist !== '')
                    <span>{{ $gist }}</span>
                @endif
                @if($files->isNotEmpty())
                    <div class="ct-files">
                        @foreach($files as $f)
                            @if($f['image'])
                                <a href="{{ $f['url'] }}" class="ct-thumb" title="{{ $f['name'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gallery, 'index' => $shot++]) }})"><img src="{{ $f['url'] }}" alt="" loading="lazy"></a>
                            @else
                                @include('partials.file-link', ['url' => $f['url'], 'fileDl' => $f['dl'] ?? null, 'name' => $f['name'], 'mime' => $f['mime'] ?? null])
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
