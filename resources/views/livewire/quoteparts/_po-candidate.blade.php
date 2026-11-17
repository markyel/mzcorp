{{-- Цена «на проверке» (17.11.2026): разбор QuoteParts не уверен — решение здесь, а не в кабинете QuoteParts.
     Ожидает $c (цена из снимка запроса: id, tiers, currency, kind, reason, quote, note, needs) и $rfqRemoteId. --}}
    @php
        $kindLabel = ['original' => 'оригинал', 'analog' => 'аналог', 'copy' => 'копия', 'replacement' => 'замена', 'refurbished' => 'б/у, восстановленная'][$c['kind'] ?? ''] ?? ($c['kind'] ?? '');
        $prices = collect($c['tiers'] ?? [])->map(fn ($t) => rtrim(rtrim(number_format((float) $t['price'], 2, ',', ' '), '0'), ',').' '.($c['currency'] ?? '?').((float) $t['min_qty'] > 1 ? ' от '.rtrim(rtrim(number_format((float) $t['min_qty'], 3, ',', ''), '0'), ',').' шт.' : ''))->implode(' · ');
    @endphp
    <div wire:key="po-cand-{{ $c['id'] }}" x-data="{ cur: '', pn: '' }"
         style="margin: 0 0 8px 0; padding: 8px 10px; border: 1px dashed var(--status-attn-fg, #b45309); border-radius: 8px; background: var(--status-attn-bg, #fffbeb); font-size: 12.5px; display: flex; flex-direction: column; gap: 4px;">
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: baseline;">
            <b style="font-size: 13px;">{{ $prices !== '' ? $prices : 'цена не распознана' }}</b>
            <span class="pa-pill" style="background: var(--neutral-100); color: var(--fg-2)">{{ $kindLabel }}</span>
            <span style="color: var(--status-attn-fg, #b45309);">на проверке: {{ $c['reason'] }}</span>
        </div>
        @if(filled($c['quote']))<span class="pa-note">Из письма: «{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $c['quote'])), 300) }}»</span>@endif
        @if(filled($c['note']))<span class="pa-meta">{{ $c['note'] }}</span>@endif
        <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 2px;">
            @if(($c['needs'] ?? null) === 'currency')
                <select x-model="cur" style="font-size: 12px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 6px;">
                    <option value="">валюта…</option><option>RUB</option><option>USD</option><option>EUR</option><option>CNY</option>
                </select>
                <button type="button" class="pa-btn pa-btn-secondary" x-bind:disabled="! cur"
                        x-on:click="$wire.decideCandidate({{ (int) $rfqRemoteId }}, {{ (int) $c['id'] }}, 'accept', { currency: cur })">Учесть</button>
            @elseif(($c['needs'] ?? null) === 'part_number')
                <button type="button" class="pa-btn pa-btn-secondary" title="Поставщик предлагает именно запрошенную деталь — учесть как оригинал"
                        x-on:click="$wire.decideCandidate({{ (int) $rfqRemoteId }}, {{ (int) $c['id'] }}, 'accept', { as_item: true })">Это наша позиция</button>
                <input type="text" x-model="pn" placeholder="артикул аналога" style="font-size: 12px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 6px; width: 150px;">
                <button type="button" class="pa-btn pa-btn-secondary" x-bind:disabled="! pn.trim()"
                        x-on:click="$wire.decideCandidate({{ (int) $rfqRemoteId }}, {{ (int) $c['id'] }}, 'accept', { part_number: pn })">Учесть как {{ $kindLabel }}</button>
            @else
                <button type="button" class="pa-btn pa-btn-secondary" @disabled($prices === '')
                        x-on:click="$wire.decideCandidate({{ (int) $rfqRemoteId }}, {{ (int) $c['id'] }}, 'accept', {})">Учесть</button>
            @endif
            <button type="button" class="pa-btn pa-btn-link" x-on:click="$wire.decideCandidate({{ (int) $rfqRemoteId }}, {{ (int) $c['id'] }}, 'reject', {})">Не учитывать</button>
        </div>
    </div>
