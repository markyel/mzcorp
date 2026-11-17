{{-- Переписка по запросу обычным письмом — на месте, под строкой (17.11.2026: у QuoteParts открывалась тут же,
     у писем уводила в заявку). Ожидает $inquiryId, $requestId, $requestCode. Вложения — встроенный просмотрщик. --}}
@php $tz = config('app.timezone'); @endphp
<div wire:key="po-mail-thread-{{ $inquiryId }}" style="margin: 6px 0 10px; padding: 10px 12px; border: 1px solid var(--border-subtle); border-radius: 8px; background: var(--bg-surface-2);">
    @forelse($this->mailThread as $m)
        @php $ours = $m->direction === \App\Enums\MailDirection::Outbound; @endphp
        <div wire:key="po-mail-msg-{{ $m->id }}" style="padding: 6px 0; {{ $loop->first ? '' : 'border-top: 1px solid var(--border-subtle);' }}">
            <div class="pa-meta" style="display: flex; gap: 6px; flex-wrap: wrap;">
                <b style="color: var(--fg-1)">{{ $ours ? 'Мы' : 'Поставщик' }}</b>
                <span>{{ $m->sent_at?->timezone($tz)->format('d.m H:i') }}</span>
                <span>· {{ $m->from_email }}</span>
                <span>· {{ \Illuminate\Support\Str::limit((string) $m->subject, 90) }}</span>
            </div>
            <div style="white-space: pre-line; font-size: 12.5px; color: var(--fg-2); margin-top: 3px;">{{ \App\Livewire\QuoteParts\PriceOffers::mailExcerpt($m) }}</div>
            @php $files = $m->attachments->where('is_inline', false); @endphp
            @if($files->isNotEmpty())
                <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;">
                    @foreach($files as $att)
                        @include('partials.file-link', ['url' => route('attachments.preview', $att->id), 'fileDl' => route('attachments.download', $att->id), 'name' => (string) $att->filename, 'mime' => $att->mime_type, 'fileClass' => 'chip chip-neutral text-[11px] max-w-[300px] truncate'])
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <span class="pa-meta">Писем по запросу нет.</span>
    @endforelse
    @if($requestId)
        <div class="pa-meta" style="margin-top: 6px;">
            Ответить поставщику — во вкладке «Поставщики» заявки:
            <a href="{{ route('requests.show', ['request' => $requestId, 'tab' => 'suppliers']) }}" class="pa-link">{{ $requestCode }}</a>
        </div>
    @endif
</div>
