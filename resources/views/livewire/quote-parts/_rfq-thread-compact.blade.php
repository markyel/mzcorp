{{-- Компактная лента переписки с поставщиком (как во вкладке «Поставщики» заявки): суть письма без
     подписи и цитаты, «весь текст» — по клику; фото — в просмотрщике. Вид сообщения — partials.chat-message.
     Ждёт $thread, $rfq, $si, $s (поставщик снимка), $supplierNames. --}}
@php
    $tz = config('app.timezone');
    $isImage = fn ($f) => in_array($f['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    $fileUrl = fn ($f) => route('quoteparts.rfq-file', [$rfq->remote_id, $f['message_id'], $f['index']] + (! empty($f['relay']) ? ['relay' => 1] : []));
    $supName = (string) (($supplierNames[$s['external_id'] ?? ''] ?? null) ?: ($s['external_id'] ?? 'Поставщик'));
@endphp
<div style="padding: 2px 4px 6px">
    @foreach($thread as $m)
        @php
            $dir = $m['direction'] ?? 'event';
            // Письмо по-китайски: перевод своей части (QuotePartsRfqService::russified), оригинал — под «весь текст».
            $original = ! empty($m['text_ru']) ? (string) ($m['text'] ?? '') : null;
            $body = (string) ($m['text_ru'] ?? $m['text'] ?? '');
            $who = (string) ($m['who'] ?? '');
        @endphp
        <div wire:key="qpc-{{ $rfq->id }}-{{ $si }}-{{ $loop->index }}">
            @include('partials.chat-message', ['msg' => [
                'dir' => in_array($dir, ['in', 'out'], true) ? $dir : 'event',
                'name' => $dir === 'out' ? 'Мы' : $supName,
                // У поставщика в «who» — «Имя (Компания)»: компания уже в имени, оставляем человека.
                'who' => trim(implode(' · ', array_filter([
                    $dir === 'in' ? trim(preg_replace('/\s*\(.*\)\s*$/u', '', $who) ?? '') : $who,
                    ! empty($m['auto']) ? 'автоответ' : null,
                    ! empty($m['cc']) ? 'копия: '.implode(', ', $m['cc']) : null,
                    $original !== null ? 'перевод' : null,
                ]))),
                'original' => $original,
                'subject' => $m['subject'] ?? null,
                'gist' => $dir === 'event' ? $body : \App\Services\QuoteParts\QuotePartsThread::gist($body),
                'body' => $body,
                'error' => $m['error'] ?? null,
                'at' => ! empty($m['at']) ? \Illuminate\Support\Carbon::parse($m['at'])->timezone($tz)->format('d.m H:i') : '',
                'files' => collect($m['files'] ?? [])->map(fn ($f) => ['url' => $fileUrl($f), 'name' => $f['name'], 'mime' => $f['mime'] ?? null, 'image' => $isImage($f)])->all(),
            ]])
        </div>
    @endforeach
</div>
