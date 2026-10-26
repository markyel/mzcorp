{{-- Запрос через QuoteParts («Снабжение»): ответы поставщиков по позициям; переписка с поставщиком, его вопросы
     и ответ ему — тем же диалогом, что везде (quote-parts._rfq-dialog). Нужны $rfq, $supplierNames; кнопка
     «Обновить» зовёт $refreshAction (по умолчанию refresh) компонента. --}}
@php $snap = $rfq->snapshot ?? []; @endphp
<div wire:key="qpr-{{ $rfq->id }}" class="border border-border rounded-md">
    <div class="flex flex-wrap items-center gap-2 px-3 py-2 border-b border-border-subtle">
        <span class="mono font-semibold">{{ $rfq->tag }}</span>
        <span class="chip {{ ($snap['status'] ?? $rfq->status) === 'open' ? 'chip-sky' : 'chip-neutral' }} text-[10.5px]">{{ ($snap['status'] ?? $rfq->status) === 'open' ? 'открыт' : 'закрыт' }}</span>
        <span class="text-fg-3 text-[12px]">{{ $rfq->created_at->format('d.m.Y H:i') }} · {{ $rfq->createdBy?->name ?? ($rfq->isSupplierQuestion() ? 'снабжение' : '') }}{{ $rfq->reply_by ? ' · ответить до '.$rfq->reply_by->format('d.m.Y') : '' }}</span>
        @if($rfq->isSupplierQuestion())
            <span class="chip chip-warn text-[10.5px]" title="Поставщик спросил по позиции из своей номенклатуры (её у него не запрашивали) — запрос открылся сам, отвечает снабжение">вопрос поставщика по позиции</span>
        @endif
        @if(! empty($snap['mirror_email']))
            <span class="chip chip-neutral text-[10.5px]" title="Ответы поставщиков приходят копией на эту почту; ответ на копию уходит поставщику">✉ дублируется на {{ $snap['mirror_email'] }}</span>
        @endif
        <span class="text-fg-4 text-[11px] ml-auto">обновлено {{ $rfq->synced_at?->format('d.m H:i') }}</span>
        <button type="button" wire:click="{{ $refreshAction ?? 'refresh' }}({{ $rfq->id }})" class="btn btn-xs">Обновить</button>
        <a href="{{ rtrim(config('services.quoteparts.url'), '/') }}/rfqs/{{ $rfq->remote_id }}" target="_blank" rel="noopener" class="btn btn-xs">В QuoteParts ↗</a>
    </div>
    @php $openQuestions = collect($snap['question_groups'] ?? [])->where('status', 'open')->count(); @endphp
    @if($openQuestions > 0)
        <div class="px-3 py-1.5 border-b border-border-subtle bg-amber-50/60 text-[12.5px] text-amber-900">
            Вопросы поставщиков без ответа: {{ $openQuestions }} — ответ в переписке с поставщиком ниже («💬 Переписка»).
        </div>
    @endif
    <div class="overflow-x-auto">
        <table class="w-full text-[12.5px]">
            <tbody>
                @php $canAnswer = $this->canAnswerQuoteParts(); @endphp
                @foreach($snap['suppliers'] ?? [] as $si => $s)
                    @foreach($s['lines'] ?? [] as $line)
                        <tr class="border-b border-border-subtle align-top">
                            @if($loop->first)
                                <td class="px-3 py-1.5 font-medium" rowspan="{{ count($s['lines']) }}">
                                    {{ $supplierNames[$s['external_id']] ?? $s['external_id'] }}
                                    @if(! empty($s['send_error']))<div class="text-[11px] text-red-700">{{ $s['send_error'] }}</div>@endif
                                    {{-- Ход ответа: отправлено → открыл форму → напоминания → ответил. --}}
                                    @php $at = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone(config('app.timezone'))->format('d.m H:i') : null; @endphp
                                    <div class="mt-0.5 space-y-px text-[11px] font-normal text-fg-3">
                                        @if(! empty($s['sent_at']))<div>✉ отправлено {{ $at($s['sent_at']) }}</div>@endif
                                        @if(! empty($s['opened_at']))<div class="text-sky-700">👁 открыл форму {{ $at($s['opened_at']) }}</div>@elseif(! empty($s['sent_at']))<div class="text-fg-4">форму не открывал</div>@endif
                                        @if(! empty($s['reminders_sent']))<div>🔔 напоминаний {{ $s['reminders_sent'] }}, последнее {{ $at($s['last_reminder_at']) }}</div>@endif
                                        @if(! empty($s['last_reply_at']))<div class="text-emerald-700">↩ ответил {{ $at($s['last_reply_at']) }}</div>@endif
                                    </div>
                                </td>
                            @endif
                            <td class="px-3 py-1.5">
                                <span class="mono">{{ $line['code'] }}</span>
                                @if(! empty($line['draft']))<span class="chip chip-sky text-[10px] ml-1" title="Предварительная позиция: товара нет в каталоге">новый</span>@endif
                                @if(! empty($line['name']))<div class="text-[11.5px] text-fg-3 truncate max-w-[260px]">{{ $line['name'] }}</div>@endif
                            </td>
                            <td class="px-3 py-1.5">
                                @if(($line['status'] ?? '') === 'offered' && ! empty($line['offer']))
                                    {{-- Все варианты поставщика по позиции: оригинал, аналог, копия, восстановленное, замена. --}}
                                    @foreach(($line['offers'] ?? []) ?: [$line['offer']] as $of)
                                        <div @class(['mt-1 pt-1 border-t border-border-subtle' => ! $loop->first])>
                                            @if(! empty($of['origin_supplier']))<div class="text-[11.5px] font-medium text-fg-2" title="Цена агента — от этого поставщика">→ {{ $of['origin_supplier'] }}</div>@endif
                                            @if(($of['kind'] ?? 'original') !== 'original')
                                                @php $rv = \App\Models\QuotePartsOffer::query()->where('remote_id', $of['id'] ?? 0)->value('review_status'); @endphp
                                                <span class="chip chip-sky text-[10.5px]">{{ \App\Models\QuotePartsOffer::kindLabel($of['kind']) }}</span>
                                                <span class="chip {{ $rv === 'accepted' ? 'chip-ok' : ($rv === 'rejected' ? 'chip-neutral' : 'chip-warn') }} text-[10px]" title="{{ \Illuminate\Support\Str::ucfirst(\App\Models\QuotePartsOffer::kindLabel($of['kind'])) }}: {{ \App\Models\QuotePartsOffer::kindHint($of['kind']) }}. Проверяет снабжение: «Снабжение» → ответы поставщиков">{{ \App\Models\QuotePartsOffer::reviewLabel($rv) }}</span>
                                                @if(! empty($of['product']))<span class="mono text-[11.5px] text-fg-2">{{ trim(($of['product']['brand'] ?? '').' '.($of['product']['part_number'] ?? '')) }}</span>@endif
                                            @endif
                                            @foreach($of['tiers'] as $t)
                                                <span class="mono font-semibold">{{ number_format((float) $t['price'], 2, ',', ' ') }} {{ $of['currency'] }}</span><span class="text-fg-4 text-[11px]"> от {{ $t['min_qty'] }}</span>@if(! $loop->last), @endif
                                            @endforeach
                                            <span class="text-fg-3 text-[11.5px]">
                                                {{ ! empty($of['lead_time_days']) ? '· '.$of['lead_time_days'].' дн.' : '' }}
                                                {{ ! empty($of['valid_until']) ? '· до '.\Illuminate\Support\Carbon::parse($of['valid_until'])->format('d.m.Y') : '' }}
                                            </span>
                                            @if(! empty($of['note']))<div class="text-fg-3 text-[11.5px]">«{{ $of['note'] }}»</div>@endif
                                            @if(! empty($of['photos']) && ! empty($of['id']))
                                                @php $gallery = collect(range(0, (int) $of['photos'] - 1))->map(fn ($i) => ['src' => route('quoteparts.offer-photo', [$of['id'], $i]), 'name' => 'Фото '.($i + 1), 'dl' => route('quoteparts.offer-photo', [$of['id'], $i])])->all(); @endphp
                                                <div class="mt-0.5 flex flex-wrap gap-1">
                                                    @foreach($gallery as $i => $ph)
                                                        <a href="{{ $ph['src'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gallery, 'index' => $i]) }})" class="block h-10 w-10 overflow-hidden rounded border border-border cursor-zoom-in"><img src="{{ $ph['src'] }}" alt="" class="h-full w-full object-cover" loading="lazy"></a>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                @else
                                    @php $st = $line['status'] ?? 'pending'; @endphp
                                    <span class="text-[11.5px] {{ $st === 'declined' ? 'text-red-700' : ($st === 'question' ? 'text-amber-700' : 'text-fg-4') }}">
                                        {{ ['pending' => 'ждём ответа', 'declined' => 'отказ', 'question' => 'вопрос к нам', 'no_answer' => 'нет ответа'][$st] ?? $st }}
                                    </span>
                                    @if(! empty($line['decline_reason']))<span class="text-fg-3 text-[11.5px]">«{{ $line['decline_reason'] }}»</span>@endif
                                    {{-- Поставщик отложил ответ до даты (ждёт обновления своей цены); дата наступила — пора отвечать. --}}
                                    @if(! empty($line['postponed_until']) && in_array($st, ['pending', 'question'], true))
                                        @php $pu = \Illuminate\Support\Carbon::parse($line['postponed_until']); @endphp
                                        <span class="chip {{ $pu->isFuture() ? 'chip-neutral' : 'chip-warn' }} text-[10.5px]" title="{{ $line['postpone_note'] ?? '' }}">
                                            {{ $pu->isFuture() ? 'поставщик отложил ответ до '.$pu->format('d.m') : 'срок, до которого отложил, наступил ('.$pu->format('d.m').')' }}
                                        </span>
                                        @if(! empty($line['postpone_note']))<span class="text-fg-3 text-[11.5px]">— {{ $line['postpone_note'] }}</span>@endif
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    {{-- Переписка с поставщиком, его вопросы и ответ ему — единым диалогом, как везде. --}}
                    @php
                        $letterCount = collect(\App\Services\QuoteParts\QuotePartsThread::of($s))->where('direction', '!=', 'event')->count();
                        $asks = collect($snap['question_groups'] ?? [])->where('status', 'open')->contains(fn ($g) => collect($g['questions'] ?? [])->contains('supplier', $s['external_id'] ?? null))
                            || collect($s['questions'] ?? [])->whereNull('group_id')->isNotEmpty();
                    @endphp
                    @if($letterCount > 0 || $asks || $canAnswer)
                        <tr class="border-b border-border-subtle">
                            <td colspan="3" class="px-3 py-2" x-data="{ open: {{ $asks ? 'true' : 'false' }} }">
                                <button type="button" @click="open = !open" class="text-[12px] text-sky-700 hover:underline">
                                    <span x-text="open ? '▾' : '▸'"></span>
                                    💬 {{ $letterCount > 0 ? 'Переписка ('.$letterCount.')' : 'Написать поставщику' }}{{ $canAnswer && $letterCount > 0 ? ' и ответ' : '' }}{{ $asks ? ' · есть вопрос' : '' }}
                                </button>
                                <div x-show="open" x-cloak class="mt-1.5">
                                    @include('livewire.quote-parts._rfq-dialog', ['rfq' => $rfq, 'supplierNames' => $supplierNames, 'refreshAction' => $refreshAction ?? 'refresh', 'onlySupplier' => $s['external_id'] ?? null])
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>
