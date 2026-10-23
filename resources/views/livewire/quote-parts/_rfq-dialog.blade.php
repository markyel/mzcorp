{{-- Диалог с одним поставщиком по запросу QuoteParts («Актуализация цен»): шапка одной строкой, открытые
     вопросы с ответом, переписка чатом, поле ответа. Цены по позициям здесь не повторяем — они в карточке
     позиции выше; отвеченные вопросы видны в переписке. Ждёт $rfq, $supplierNames, $onlySupplier, $refreshAction. --}}
@php
    $snap = $rfq->snapshot ?? [];
    $tz = config('app.timezone');
    $canAnswer = $this->canAnswerQuoteParts();
    $si = collect($snap['suppliers'] ?? [])->search(fn ($x) => ($x['external_id'] ?? null) === $onlySupplier);
    $s = $si === false ? null : $snap['suppliers'][$si];
    $supName = $s ? (string) ($supplierNames[$s['external_id']] ?? $s['external_id']) : '';
    // Открытые вопросы этого поставщика: сгруппированные (ответ уходит всем спросившим) и одиночные.
    $openGroups = collect($snap['question_groups'] ?? [])->where('status', 'open')
        ->filter(fn ($g) => collect($g['questions'] ?? [])->contains('supplier', $onlySupplier))->values();
    $loose = $s ? collect($s['questions'] ?? [])->whereNull('group_id')->values() : collect();
    $thread = $s ? collect($s['letters'] ?? [])->map(fn ($l) => ['direction' => 'out', 'kind' => 'letter', 'at' => $l['at'], 'who' => $l['to'] ? 'кому: '.$l['to'] : null, 'subject' => $l['subject'], 'text' => $l['text'], 'error' => $l['error'] ?? null, 'files' => []])
        ->concat($s['thread'] ?? [])
        ->when(! empty($s['reminders_sent']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['last_reminder_at'], 'text' => 'QuoteParts отправил напоминание'.($s['reminders_sent'] > 1 ? ' (всего '.$s['reminders_sent'].')' : '')]))
        ->when(! empty($s['opened_at']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['opened_at'], 'text' => 'Поставщик открыл форму запроса']))
        ->sortBy(fn ($m) => $m['at'] ?? '')->values()->all() : [];
@endphp
<style>
    .qd{border:1px solid var(--border);border-radius:8px;background:var(--bg-surface)}
    .qd-head{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;padding:10px 14px;border-bottom:1px solid var(--border-subtle);font-size:12.5px;color:var(--fg-3)}
    .qd-head b{color:var(--fg-1);font-weight:600;font-size:13px}
    .qd-sec{padding:10px 14px;border-bottom:1px solid var(--border-subtle)}
    .qd-ask{border:1px solid var(--amber-300);background:var(--amber-50, #fffbeb);border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;font-size:12.5px}
    .qd-ta{box-sizing:border-box;width:100%;font:inherit;border:1px solid var(--border-strong);border-radius:6px;padding:8px 10px;font-size:13px;resize:vertical;outline:none;background:var(--bg-surface)}
    .qd-ta:focus{border-color:var(--sky-500)}
    .qd-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
    .qd-hint{font-size:11.5px;color:var(--fg-4)}
    .qd-link{font-size:12px;color:var(--sky-700)}
</style>
<div wire:key="qpd-{{ $rfq->id }}-{{ $onlySupplier }}" class="qd">
    <div class="qd-head">
        <b>{{ $supName ?: 'Поставщик' }}</b>
        <span>· запрос <span class="mono">{{ trim((string) $rfq->tag, '[]') }}</span> от {{ $rfq->created_at->timezone($tz)->format('d.m H:i') }}{{ $rfq->createdBy ? ' · '.$rfq->createdBy->name : '' }}</span>
        @if(! empty($snap['mirror_email']))<span title="Ответы поставщика приходят копией на эту почту; ответ на копию уходит поставщику">· копии на {{ $snap['mirror_email'] }}</span>@endif
        @if($s && ! empty($s['send_error']))<span style="color: var(--red-700)">· не отправлено: {{ $s['send_error'] }}</span>@endif
        <span style="margin-left: auto" class="qd-hint">обновлено {{ $rfq->synced_at?->timezone($tz)->format('d.m H:i') }}</span>
        <button type="button" wire:click="{{ $refreshAction ?? 'refresh' }}({{ $rfq->id }})" class="btn btn-xs">Обновить</button>
        <a href="{{ rtrim(config('services.quoteparts.url'), '/') }}/rfqs/{{ $rfq->remote_id }}" target="_blank" rel="noopener" class="btn btn-xs">В QuoteParts ↗</a>
    </div>

    @if($s === null)
        <div class="qd-sec qd-hint">Поставщика нет в этом запросе.</div>
    @else
        {{-- Открытые вопросы — то, что ждёт нашего ответа. --}}
        @if($openGroups->isNotEmpty() || $loose->isNotEmpty())
            <div class="qd-sec" style="display: flex; flex-direction: column; gap: 8px">
                @foreach($openGroups as $g)
                    @php $askers = collect($g['questions'] ?? [])->pluck('supplier')->unique()->count(); $mine = collect($g['questions'] ?? [])->where('supplier', $onlySupplier); @endphp
                    <div wire:key="qpdg-{{ $rfq->id }}-{{ $g['id'] }}" class="qd-ask">
                        <div><b style="color: var(--amber-800, #92400e)">Ждёт ответа:</b> <span style="font-weight: 600">{{ $g['title_ru'] }}</span>@if($askers > 1)<span class="qd-hint"> · спрашивают {{ $askers }} поставщика — ответ уйдёт всем</span>@endif</div>
                        @foreach($mine as $q)
                            <div style="color: var(--fg-2)">{{ $q['text_ru'] ?: $q['text'] }}@if($q['text_ru'] && $q['text_ru'] !== $q['text'])<span class="qd-hint"> («{{ $q['text'] }}»)</span>@endif</div>
                        @endforeach
                        @if($canAnswer)
                            @php $needsEn = $this->quotePartsGroupNeedsEnglish($g); @endphp
                            <textarea wire:model="qpGroupRu.{{ $rfq->id }}.{{ $g['id'] }}" rows="2" placeholder="Ответ по-русски" class="qd-ta"></textarea>
                            @if($needsEn)
                                <textarea wire:model="qpGroupEn.{{ $rfq->id }}.{{ $g['id'] }}" rows="2" placeholder="English version — для англоязычных поставщиков" class="qd-ta"></textarea>
                            @endif
                            <div class="qd-row">
                                <button type="button" wire:click="answerQuotePartsGroup({{ $rfq->id }}, {{ $g['id'] }})" wire:loading.attr="disabled" wire:target="answerQuotePartsGroup" class="btn btn-sm btn-primary">Ответить{{ $askers > 1 ? ' всем ('.$askers.')' : '' }}</button>
                                @if($needsEn)
                                    <button type="button" wire:click="translateQuotePartsGroup({{ $rfq->id }}, {{ $g['id'] }})" wire:loading.attr="disabled" wire:target="translateQuotePartsGroup" class="btn btn-sm">🌐 Перевести</button>
                                @endif
                                <label class="btn btn-sm cursor-pointer">📎 Файлы
                                    <input type="file" multiple wire:model="qpGroupFiles.{{ $rfq->id }}.{{ $g['id'] }}" class="hidden" accept="image/*,.pdf,.xlsx,.xls,.doc,.docx,.zip">
                                </label>
                                @foreach($qpGroupFiles[$rfq->id][$g['id']] ?? [] as $up)
                                    <span class="chip chip-neutral text-[11px] max-w-[220px] truncate">📎 {{ $up->getClientOriginalName() }}</span>
                                @endforeach
                                <button type="button" wire:click="closeQuotePartsQuestions({{ $rfq->id }}, {{ $g['id'] }})" wire:confirm="Закрыть вопрос без ответа? Поставщикам ничего не уйдёт, вопрос перейдёт в отвеченные."
                                        class="btn btn-sm" style="margin-left: auto">Закрыть без ответа</button>
                            </div>
                        @endif
                    </div>
                @endforeach
                @if($loose->isNotEmpty())
                    <div class="qd-ask">
                        <div><b style="color: var(--amber-800, #92400e)">Поставщик спрашивает</b> <span class="qd-hint">— ответьте в поле ниже</span></div>
                        @foreach($loose as $q)<div style="color: var(--fg-2)">{{ $q['text'] }}</div>@endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Переписка. --}}
        <div class="qd-sec">
            @if($thread !== [])
                @include('livewire.quote-parts._rfq-thread-compact')
            @else
                <div class="qd-hint">Переписки пока нет.</div>
            @endif
        </div>

        {{-- Ответ поставщику. --}}
        @if($canAnswer)
            @php $supLang = $this->quotePartsSupplierLanguage($s['external_id'] ?? null); $cc = $qpCc[$rfq->id][$si] ?? ''; @endphp
            <div class="qd-sec" style="border-bottom: 0; display: flex; flex-direction: column; gap: 8px; background: var(--bg-surface-2); border-radius: 0 0 8px 8px" x-data="{ cc: {{ $cc !== '' ? 'true' : 'false' }} }">
                <textarea wire:model="qpAnswers.{{ $rfq->id }}.{{ $si }}" rows="3" class="qd-ta"
                          placeholder="{{ $supLang === 'ru' ? 'Ответ поставщику — по-русски' : 'Answer in English — поставщик работает на английском' }}"></textarea>
                <div x-show="cc" x-cloak class="qd-row">
                    <span class="qd-hint" style="width: 48px">Копия</span>
                    <input type="text" wire:model="qpCc.{{ $rfq->id }}.{{ $si }}" placeholder="адреса через запятую" class="qd-ta" style="flex: 1; height: 30px; padding: 4px 10px">
                </div>
                @if(! empty($qpFiles[$rfq->id][$si]))
                    <div class="qd-row">
                        @foreach($qpFiles[$rfq->id][$si] as $fi => $up)
                            <span wire:key="qpdf-{{ $rfq->id }}-{{ $si }}-{{ $fi }}" class="chip chip-neutral text-[11px] max-w-[260px]">
                                <span class="truncate">📎 {{ $up->getClientOriginalName() }}</span>
                                <button type="button" wire:click="removeQuotePartsFile({{ $rfq->id }}, {{ $si }}, {{ $fi }})" class="ml-1 text-fg-4 hover:text-red-700" title="Убрать">✕</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                @error("qpFiles.$rfq->id.$si.*")<div class="text-[11px] text-red-700">{{ $message }}</div>@enderror
                <div class="qd-row">
                    <button type="button" wire:click="answerQuotePartsSupplier({{ $rfq->id }}, {{ $si }})" wire:loading.attr="disabled" wire:target="answerQuotePartsSupplier" class="btn btn-sm btn-primary">Отправить поставщику</button>
                    <label class="btn btn-sm cursor-pointer" title="До 25 МБ на файл">📎 Файлы
                        <input type="file" multiple wire:model="qpFiles.{{ $rfq->id }}.{{ $si }}" class="hidden" accept="image/*,.pdf,.xlsx,.xls,.doc,.docx,.zip">
                    </label>
                    <span wire:loading wire:target="qpFiles.{{ $rfq->id }}.{{ $si }}" class="qd-hint">загружаем…</span>
                    @if($supLang !== 'ru')
                        <button type="button" wire:click="translateQuotePartsAnswer({{ $rfq->id }}, {{ $si }})" wire:loading.attr="disabled" wire:target="translateQuotePartsAnswer" class="btn btn-sm"
                                title="Напишите по-русски и переведите — перевод встанет в поле, его можно поправить">🌐 Перевести на английский</button>
                    @endif
                    <button type="button" x-show="! cc" x-on:click="cc = true" class="qd-link">+ копия</button>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--fg-2); cursor: pointer">
                        <input type="checkbox" wire:model="qpNoSign.{{ $rfq->id }}.{{ $si }}" style="width: 14px; height: 14px"> без подписи
                    </label>
                    <span class="qd-hint" style="margin-left: auto" title="Позиции «вопрос к нам» после ответа снова ждут цен">уйдёт письмом от MyZip ответом на его письмо, с цитатой и подписью</span>
                </div>
            </div>
        @endif
    @endif
</div>
