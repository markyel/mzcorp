{{-- Компактная лента переписки с поставщиком (как во вкладке «Поставщики» заявки): суть письма без
     подписи и цитаты в одну-две строки, «весь текст» — по клику; фото — в просмотрщике.
     Ждёт $thread, $rfq, $si. --}}
@php
    $tz = config('app.timezone');
    $isImage = fn ($f) => in_array($f['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    $fileUrl = fn ($f) => route('quoteparts.rfq-file', [$rfq->remote_id, $f['message_id'], $f['index']] + (! empty($f['relay']) ? ['relay' => 1] : []));
@endphp
<div style="padding-bottom: 4px">
    @foreach($thread as $m)
        @php
            $dir = $m['direction'] ?? 'event';
            $at = ! empty($m['at']) ? \Illuminate\Support\Carbon::parse($m['at'])->timezone($tz)->format('d.m H:i') : '';
            $body = (string) ($m['text'] ?? '');
            $gist = $dir === 'event' ? $body : \App\Services\QuoteParts\QuotePartsThread::gist($body);
            $who = match ($dir) {
                'out' => 'Мы'.(! empty($m['who']) ? ' · '.$m['who'] : ''),
                'in' => ($m['who'] ?? null) ?: 'Поставщик',
                default => 'QuoteParts',
            };
            $files = collect($m['files'] ?? []);
            $gallery = $files->filter($isImage)->values()->map(fn ($f) => ['src' => $fileUrl($f), 'name' => $f['name'], 'dl' => $fileUrl($f)])->all();
            $shot = 0;
        @endphp
        <div wire:key="qpc-{{ $rfq->id }}-{{ $si }}-{{ $loop->index }}" x-data="{ full: false }"
             style="display: grid; grid-template-columns: 16px minmax(0, 1fr) auto; gap: 10px; font-size: 12.5px; border-radius: 6px; border-left: 3px solid transparent; {{ match ($dir) {
                 'in' => 'padding: 7px 10px; margin-top: 6px; background: var(--emerald-50); border-left-color: var(--emerald-600)',
                 'out' => 'padding: 7px 10px; margin-top: 6px; background: var(--bg-surface-2); border-left-color: var(--sky-500)',
                 default => 'padding: 2px 10px; margin-top: 2px; font-size: 12px; color: var(--fg-4)',
             } }}">
            <span style="font-weight: 700; color: {{ $dir === 'out' ? 'var(--sky-700)' : ($dir === 'in' ? 'var(--emerald-700)' : 'var(--fg-4)') }}">{{ $dir === 'out' ? '→' : ($dir === 'in' ? '←' : '·') }}</span>
            <div style="display: flex; flex-direction: column; gap: 2px; min-width: 0">
                <span style="font-weight: {{ $dir === 'event' ? 400 : ($dir === 'in' ? 600 : 500) }}; color: {{ $dir === 'event' ? 'var(--fg-4)' : 'var(--fg-1)' }}">{{ $who }}@if(! empty($m['auto']))<span style="color: var(--fg-4); font-weight: 400"> · автоответ</span>@endif @if(! empty($m['cc']))<span style="color: var(--fg-4); font-weight: 400"> · копия: {{ implode(', ', $m['cc']) }}</span>@endif</span>
                @if(! empty($m['subject']))<span style="font-size: 12px; color: var(--fg-2)">{{ $m['subject'] }}</span>@endif
                @if(! empty($m['error']))<span style="font-size: 11.5px; color: var(--red-700)">не отправлено: {{ $m['error'] }}</span>@endif
                @if($dir !== 'event' && $gist !== trim(preg_replace('/\s+/u', ' ', $body) ?? ''))
                    <span x-show="! full" style="color: var(--fg-3); text-wrap: pretty">{{ $gist }} <button type="button" x-on:click="full = true" style="color: var(--sky-700)">весь текст</button></span>
                    <span x-show="full" x-cloak style="color: var(--fg-2); white-space: pre-wrap; max-height: 420px; overflow-y: auto">{{ $body }}
<button type="button" x-on:click="full = false" style="color: var(--sky-700)">свернуть</button></span>
                @else
                    <span style="color: var(--fg-3); text-wrap: pretty">{{ $gist }}</span>
                @endif
                @if($files->isNotEmpty())
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 4px">
                        @foreach($files as $f)
                            @if($isImage($f))
                                <a href="{{ $fileUrl($f) }}" title="{{ $f['name'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gallery, 'index' => $shot++]) }})"
                                   style="display: block; width: 56px; height: 56px; overflow: hidden; border-radius: 6px; border: 1px solid var(--border); cursor: zoom-in"><img src="{{ $fileUrl($f) }}" alt="" loading="lazy" style="width: 100%; height: 100%; object-fit: cover"></a>
                            @else
                                @include('partials.file-link', ['url' => $fileUrl($f), 'name' => $f['name'], 'mime' => $f['mime'] ?? null])
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
            <span style="color: var(--fg-3); white-space: nowrap; font-variant-numeric: tabular-nums">{{ $at }}</span>
        </div>
    @endforeach
</div>
