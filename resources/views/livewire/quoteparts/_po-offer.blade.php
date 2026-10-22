{{-- Строка предложения в позиции «Актуализации цен» (дизайн «Актуализация цен», 10.2026). Ожидает $r (строка
     предложения), $it (позиция), $chosen (id выбранного предложения позиции), $best (id лучшего оригинала),
     $only (у позиции одно годное предложение; не используется), $pos (PricePosition|null), $reopened (позиция вернулась с новым).
     Строки всегда белые: статус позиции — только в её шапке. --}}
@php
    $sources = ['form' => 'форма запроса', 'email' => 'письмо', 'cabinet' => 'кабинет', 'price_list' => 'прайс', 'api' => 'API'];
    $tz = config('app.timezone');
    $kind = $r['kind'] ?? 'original';
    $ignored = $r['ignored'] ?? false;
    $isChosen = $chosen === $r['id'];
    $cl = $this->clarifications[$r['id']] ?? null;
    $clState = $cl['state'] ?? null;
    $clWaiting = $clState === \App\Services\QuoteParts\OfferClarifications::WAITING;
    // «новое»: пришло после завершения сбора цен и ещё не выгружено.
    $isNew = $reopened && $pos?->closed_at && $r['created'] && $r['created']->gt($pos->closed_at)
        && ! ($pos->exported_at && $pos->exported_at->gt($r['created']));
    $wasBought = $reopened && $isChosen && (int) ($pos?->closed_offer['offer_id'] ?? 0) === $r['id'];
    $tiers = collect($r['tiers'])->filter(fn ($t) => isset($t['price']))->sortBy(fn ($t) => (float) ($t['min_qty'] ?? 1))->values();
    $moq = (float) ($tiers->first()['min_qty'] ?? 1);
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $terms = array_values(array_filter([
        $r['valid'] ? 'до '.$r['valid']->format('d.m.Y') : null,
        $r['lead'] !== null ? 'поставка '.$r['lead'].' дн.' : null,
        $moq > 1 ? 'мин. партия '.$num($moq) : null,
    ]));

    $open = $dialog === $r['id'];
    $nLetters = $this->threadCounts[$r['id']] ?? null;
@endphp
<div wire:key="po-{{ $r['id'] }}" class="pa-offer {{ $open ? 'is-open' : '' }}">
    <div class="pa-grid pa-row">
        <div class="pa-col" style="opacity: {{ $ignored || $r['stale'] ? .55 : 1 }}">
            <div class="pa-who">
                <span class="pa-sup">{{ $r['supplier'] }}</span>
                @if($isNew)<span class="pa-pill pa-pill-new">новое</span>@endif
                @if($best === $r['id'])<span class="pa-pill pa-pill-ok">лучшее</span>@endif
                @if($r['stale'])<span class="pa-pill pa-pill-neutral">устарело</span>@endif
            </div>
            <span class="pa-meta">{{ $r['at']?->timezone($tz)->format('d.m H:i') }} · {{ $sources[$r['source']] ?? $r['source'] }}</span>
            @if($r['key'])
                <button type="button" wire:click="openDialog({{ $r['id'] }})" class="pa-link"
                        title="{{ $r['rfq_remote_id'] ? 'Переписка с поставщиком по запросу '.$r['rfq_tag'] : 'Обращения к поставщику через QuoteParts' }}">{{ $open ? '▾' : '▸' }} {{ $r['rfq_remote_id'] ? 'переписка'.($nLetters ? ' ('.$nLetters.')' : '') : 'обращения' }}</button>
            @endif
        </div>

        <div class="pa-col pa-price" style="opacity: {{ $ignored || $r['stale'] ? .55 : 1 }}">
            <span class="pa-kind {{ $kind === 'original' ? 'is-orig' : 'is-dev' }}">{{ $kind === 'original' ? 'оригинал' : mb_strtolower(\App\Models\QuotePartsOffer::kindLabel($kind)) }}</span>
            @if($tiers->count() > 1)
                @foreach($tiers as $t)
                    <span class="pa-amount">{{ $money($t['price']) }} {{ $r['currency'] }}<span class="pa-from"> от {{ $num($t['min_qty'] ?? 1) }}</span></span>
                @endforeach
            @else
                <span class="pa-amount">{{ $r['price'] !== null ? $money($r['price']).' '.$r['currency'] : '—' }}</span>
            @endif
            @if($r['rub'] !== null)<span class="pa-meta">≈ {{ number_format($r['rub'], 0, ',', ' ') }} ₽</span>@endif
        </div>

        <div class="pa-col pa-terms" style="opacity: {{ $ignored || $r['stale'] ? .55 : 1 }}">
            @forelse($terms as $i => $t)
                <span style="{{ $i > 0 ? 'color: var(--fg-3)' : '' }}">{{ $t }}</span>
            @empty
                <span style="color: var(--fg-4)">—</span>
            @endforelse
        </div>

        <div class="pa-col" style="gap: 6px">
            @if($kind !== 'original' && $r['product'])<span class="pa-note">{{ \App\Models\QuotePartsOffer::kindLabel($kind) }}: <span class="mono">{{ $r['product'] }}</span></span>@endif
            @if($r['note'])<span class="pa-note">Поставщик: «{{ $r['note'] }}»</span>@endif
            @if($clState === \App\Services\QuoteParts\OfferClarifications::ANSWERED)
                <span class="pa-pill pa-pill-info" style="align-self: flex-start; font-size: 12px; font-weight: 500; padding: 2px 8px">↩ ответил на уточнение {{ $cl['answered_at']->timezone($tz)->format('d.m H:i') }} — решите по ответу</span>
            @elseif($clWaiting)
                <span class="pa-note" style="color: var(--status-attn-fg)">Задан уточняющий вопрос — ждём ответа до {{ $cl['until']->timezone($tz)->format('d.m H:i') }}.</span>
            @elseif($cl)
                <span class="pa-note" style="color: var(--fg-3)">На уточнение не ответил за рабочий день.</span>
            @endif
            @if(! $r['cid'])
                <span class="pa-note" style="color: var(--status-attn-fg)">Нет в каталоге 1С — закупку отметите после создания позиции.</span>
            @else
                {{-- Комментарий в выгрузке 1С: собран из предложения, снабжение может поправить (сохраняется при уходе из поля). --}}
                <div x-data="{ v: @js((string) ($r['export_comment'] ?? '')), saved: @js((string) ($r['export_comment'] ?? '')) }" class="pa-col" style="gap: 2px">
                    <input type="text" x-model="v" placeholder="комментарий для 1С" class="pa-input" title="Комментарий к этой цене в выгрузке 1С"
                           x-on:blur="if (v !== saved) { saved = v; $wire.saveComment({{ $r['id'] }}, v) }" x-on:keydown.enter="$el.blur()">
                    @if($r['comment_edited'] ?? false)
                        <span class="pa-meta" style="font-size: 11px">поправлено · <button type="button" wire:click="saveComment({{ $r['id'] }}, null)" class="pa-link">вернуть собранный</button></span>
                    @endif
                </div>
            @endif
        </div>

        <div class="pa-col pa-decide">
            @if($ignored)
                <span class="pa-state is-neutral"><span class="dot"></span>игнорируем</span>
                <button type="button" wire:click="ignore({{ $r['id'] }}, false)" class="pa-btn pa-btn-link">вернуть</button>
            @elseif($isChosen)
                <span class="pa-state is-ok"><span class="dot"></span>{{ $wasBought ? 'закупали · выгружено '.$pos->closed_at->timezone($tz)->format('d.m H:i') : ($pos?->in_export_at && $pos?->exported_at ? 'закупаем · выгружено' : 'закупаем') }}</span>
                <button type="button" wire:click="clearChoice({{ $r['cid'] }})" class="pa-btn pa-btn-link" title="Снять выбор поставщика">вернуть</button>
            @elseif($clWaiting)
                <span class="pa-state is-info"><span class="dot"></span>запрошено уточнение</span>
                <button type="button" wire:click="clearClarification({{ $r['id'] }})" class="pa-btn pa-btn-link" title="Снять ожидание ответа и решить сейчас">вернуть</button>
            @else
                @if($r['cid'] && $r['key'] && ! $r['stale'])
                    <button type="button" wire:click="choose({{ $r['id'] }})" class="pa-btn pa-btn-secondary"
                            title="Закупаем у этого поставщика — идёт в выгрузку 1С как выбранный{{ $kind !== 'original' ? '; '.mb_strtolower(\App\Models\QuotePartsOffer::kindLabel($kind)).' — как цена позиции, с примечанием' : '' }}">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Закупаем здесь</button>
                @endif
                @if($r['key'])
                    <button type="button" wire:click="openDialog({{ $r['id'] }})" class="pa-btn pa-btn-secondary {{ $open ? 'is-on' : '' }}" title="Уточнить у поставщика — переписка по запросу или обращение">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Уточнить</button>
                @endif
                @unless($r['stale'])
                    <button type="button" wire:click="ignore({{ $r['id'] }}, true)" wire:confirm="Игнорировать это предложение? Его цена не пойдёт в выгрузку 1С."
                            class="pa-btn pa-btn-ghost" title="Поставщик предложил не то — цена не пойдёт в выгрузку 1С и в выбор">Игнорировать</button>
                @endunless
            @endif
        </div>
    </div>

    @if($open)
        <div class="pa-thread">
            @if($rfq = $this->dialogRfq)
                <div class="pa-meta" style="margin-bottom: 6px">Переписка с поставщиком по запросу <span class="mono">{{ $rfq->tag }}</span> — ответ уйдёт в его ветку письмом от MyZip. Отправка отмечает предложение «запрошено уточнение».</div>
                @include('livewire.quote-parts._rfq-card', ['rfq' => $rfq, 'supplierNames' => app(\App\Services\QuoteParts\QuotePartsRfqService::class)->supplierNames([$rfq]), 'refreshAction' => 'refreshQuotePartsRfq', 'onlySupplier' => $r['key']])
            @elseif($sup = $this->dialogSupplier)
                <div class="pa-meta" style="margin-bottom: 6px">Предложение не по запросу ({{ $sources[$r['source']] ?? $r['source'] }}) — уточнить можно обращением к поставщику через QuoteParts.</div>
                <livewire:suppliers.quote-parts-conversations :supplier="$sup" :key="'po-conv-'.$r['id']" />
            @else
                <div class="pa-meta">Поставщик не найден в справочнике — написать ему из mzCorp нельзя.</div>
            @endif
        </div>
    @endif
</div>
