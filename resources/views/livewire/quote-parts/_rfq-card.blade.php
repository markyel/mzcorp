{{-- Запрос через QuoteParts: снимок ответов поставщиков. Нужны $rfq, $supplierNames; кнопка «Обновить» зовёт $refreshAction (по умолчанию refresh) компонента. --}}
@php $snap = $rfq->snapshot ?? []; @endphp
<div wire:key="qpr-{{ $rfq->id }}" class="border border-border rounded-md">
    <div class="flex flex-wrap items-center gap-2 px-3 py-2 border-b border-border-subtle">
        <span class="mono font-semibold">{{ $rfq->tag }}</span>
        <span class="chip {{ ($snap['status'] ?? $rfq->status) === 'open' ? 'chip-sky' : 'chip-neutral' }} text-[10.5px]">{{ ($snap['status'] ?? $rfq->status) === 'open' ? 'открыт' : 'закрыт' }}</span>
        <span class="text-fg-3 text-[12px]">{{ $rfq->created_at->format('d.m.Y H:i') }} · {{ $rfq->createdBy?->name }}{{ $rfq->reply_by ? ' · ответить до '.$rfq->reply_by->format('d.m.Y') : '' }}</span>
        @if(! empty($snap['mirror_email']))
            <span class="chip chip-neutral text-[10.5px]" title="Ответы поставщиков приходят копией на эту почту; ответ на копию уходит поставщику">✉ дублируется на {{ $snap['mirror_email'] }}</span>
        @endif
        <span class="text-fg-4 text-[11px] ml-auto">обновлено {{ $rfq->synced_at?->format('d.m H:i') }}</span>
        <button type="button" wire:click="{{ $refreshAction ?? 'refresh' }}({{ $rfq->id }})" class="btn btn-xs">Обновить</button>
        <a href="{{ rtrim(config('services.quoteparts.url'), '/') }}/rfqs/{{ $rfq->remote_id }}" target="_blank" rel="noopener" class="btn btn-xs">В QuoteParts ↗</a>
    </div>
    {{-- Вопросы поставщиков: одинаковые по смыслу собраны в группы; ответ — всем спросившим разом. --}}
    @php $qGroups = collect($snap['question_groups'] ?? []); $openGroups = $qGroups->where('status', 'open'); @endphp
    @if($qGroups->isNotEmpty())
        <div class="px-3 py-2 border-b border-border-subtle space-y-2 {{ $openGroups->isNotEmpty() ? 'bg-amber-50/60' : '' }}">
            <div class="flex items-center gap-2 text-[12.5px]">
                <span class="font-medium text-fg-1">Вопросы поставщиков</span>
                <span class="text-fg-3">{{ $openGroups->isNotEmpty() ? 'без ответа: '.$openGroups->count() : 'все отвечены' }}</span>
                @if($openGroups->isNotEmpty() && $rfq->request_id && method_exists($this, 'askClientAboutQuotePartsQuestions') && $this->canAnswerQuoteParts())
                    <button type="button" wire:click="askClientAboutQuotePartsQuestions({{ $rfq->id }})" class="btn btn-xs ml-auto"
                            title="Черновик письма клиенту с этими вопросами по-русски, без имён поставщиков — откроется в редакторе">✉ Спросить клиента</button>
                @endif
            </div>
            @foreach($qGroups as $g)
                @php $askers = collect($g['questions'] ?? [])->pluck('supplier')->unique()->count(); @endphp
                <div wire:key="qpg-{{ $rfq->id }}-{{ $g['id'] }}" class="rounded-md border {{ $g['status'] === 'open' ? 'border-amber-300 bg-surface' : 'border-border-subtle bg-surface-2' }} px-2.5 py-1.5 text-[12.5px]">
                    <div>
                        <span class="font-medium">{{ $g['title_ru'] }}</span>
                        <span class="text-fg-3">· {{ $askers === 1 ? 'спрашивает 1 поставщик' : 'спрашивают '.$askers.' поставщика' }}</span>
                        @if($g['item_code'])<span class="mono text-fg-4 text-[11px]">· {{ $g['item_code'] }}</span>@endif
                        @if($g['status'] !== 'open')<span class="text-emerald-700 text-[11px]">· отвечено</span>@endif
                    </div>
                    <ul class="mt-0.5 text-fg-2">
                        @foreach($g['questions'] ?? [] as $q)
                            <li>{{ $supplierNames[$q['supplier']] ?? $q['supplier'] }}: {{ $q['text_ru'] ?: $q['text'] }}@if($q['text_ru'] && $q['text_ru'] !== $q['text']) <span class="text-fg-4">(«{{ $q['text'] }}»)</span>@endif</li>
                        @endforeach
                    </ul>
                    @if($g['status'] === 'open' && $this->canAnswerQuoteParts())
                        @php $needsEn = $this->quotePartsGroupNeedsEnglish($g); @endphp
                        <div class="mt-1.5 space-y-1">
                            <textarea wire:model="qpGroupRu.{{ $rfq->id }}.{{ $g['id'] }}" rows="2" placeholder="Ответ по-русски — уйдёт всем, кто спросил"
                                      class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                            @if($needsEn)
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="translateQuotePartsGroup({{ $rfq->id }}, {{ $g['id'] }})" wire:loading.attr="disabled" wire:target="translateQuotePartsGroup" class="btn btn-xs">🌐 Перевести для англоязычных</button>
                                    <span class="text-[11px] text-fg-4">среди спросивших есть поставщики с языком English — им уйдёт перевод, его можно поправить</span>
                                </div>
                                <textarea wire:model="qpGroupEn.{{ $rfq->id }}.{{ $g['id'] }}" rows="2" placeholder="English version"
                                          class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                            @endif
                            <button type="button" wire:click="answerQuotePartsGroup({{ $rfq->id }}, {{ $g['id'] }})" wire:loading.attr="disabled" wire:target="answerQuotePartsGroup"
                                    class="btn btn-sm btn-primary">Ответить всем ({{ $askers }})</button>
                        </div>
                    @endif
                </div>
            @endforeach
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
                                            @if(($of['kind'] ?? 'original') !== 'original')
                                                @php $rv = \App\Models\QuotePartsOffer::query()->where('remote_id', $of['id'] ?? 0)->value('review_status'); @endphp
                                                <span class="chip chip-sky text-[10.5px]">{{ \App\Models\QuotePartsOffer::kindLabel($of['kind']) }}</span>
                                                <span class="chip {{ $rv === 'accepted' ? 'chip-ok' : ($rv === 'rejected' ? 'chip-neutral' : 'chip-warn') }} text-[10px]" title="Отклонения разбирает снабжение: «Снабжение» → ответы поставщиков">{{ ['accepted' => 'принято к закупке', 'rejected' => 'отклонено'][$rv] ?? 'на разборе' }}</span>
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
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    {{-- Вопросы поставщика, переписка и ответ ему (письмом от MyZip в его переписку). --}}
                    @php
                        $questions = collect($s['questions'] ?? [])->whereNull('group_id')->values()->all();
                        // Лента как у прямого запроса из mzCorp: что ушло поставщику (письмо запроса, напоминания) и что он ответил.
                        $thread = collect($s['letters'] ?? [])->map(fn ($l) => ['direction' => 'out', 'kind' => 'letter', 'at' => $l['at'], 'who' => $l['to'] ? 'кому: '.$l['to'] : null, 'subject' => $l['subject'], 'text' => $l['text'], 'restored' => $l['restored'] ?? false, 'error' => $l['error'] ?? null, 'files' => []])
                            ->concat($s['thread'] ?? [])
                            ->when(! empty($s['reminders_sent']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['last_reminder_at'], 'text' => 'QuoteParts отправил напоминание'.($s['reminders_sent'] > 1 ? ' (всего '.$s['reminders_sent'].')' : '').' по позициям без ответа']))
                            ->when(! empty($s['opened_at']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['opened_at'], 'text' => 'Поставщик открыл форму запроса']))
                            ->sortBy(fn ($m) => $m['at'] ?? '')->values()->all();
                        $letterCount = collect($thread)->where('direction', '!=', 'event')->count();
                    @endphp
                    @if($questions !== [] || $thread !== [] || $canAnswer)
                        <tr class="border-b border-border-subtle">
                            <td colspan="3" class="px-3 py-2" x-data="{ open: {{ $questions !== [] ? 'true' : 'false' }} }">
                                @if($questions !== [])
                                    <div class="rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1.5 mb-1.5 text-[12.5px] text-amber-900">
                                        <span class="font-medium">Поставщик спрашивает:</span>
                                        <ul class="list-disc pl-5">@foreach($questions as $q)<li>{{ $q['text'] }}</li>@endforeach</ul>
                                    </div>
                                @endif
                                <button type="button" @click="open = !open" class="text-[12px] text-sky-700 hover:underline">
                                    <span x-text="open ? '▾' : '▸'"></span>
                                    {{ $thread !== [] ? 'Переписка ('.$letterCount.')' : 'Написать поставщику' }}{{ $canAnswer && $thread !== [] ? ' и ответ' : '' }}
                                </button>
                                <div x-show="open" x-cloak class="mt-1.5 space-y-1.5">
                                    @foreach($thread as $m)
                                        @if($m['direction'] === 'event')
                                            <div wire:key="qpt-{{ $rfq->id }}-{{ $si }}-{{ $loop->index }}" class="text-center text-[11px] text-fg-4">· {{ $m['text'] }} · {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }} ·</div>
                                            @continue
                                        @endif
                                        <div wire:key="qpt-{{ $rfq->id }}-{{ $si }}-{{ $loop->index }}"
                                             class="rounded-md px-2.5 py-1.5 text-[12.5px] {{ $m['direction'] === 'out' ? 'bg-sky-50 border border-sky-200 ml-8' : 'bg-surface-2 border border-border-subtle mr-8' }}">
                                            <div class="text-[11px] text-fg-3 mb-0.5">
                                                {{ $m['direction'] === 'out' ? 'Мы' : 'Поставщик' }}{{ $m['who'] ? ' · '.$m['who'] : '' }}
                                                · {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}
                                                {{ ! empty($m['auto']) ? '· автоответ' : '' }}
                                                {{ ! empty($m['restored']) ? '· текст восстановлен по запросу' : '' }}
                                            </div>
                                            @if(! empty($m['subject']))<div class="font-medium text-fg-1 mb-0.5">{{ $m['subject'] }}</div>@endif
                                            @if(! empty($m['error']))<div class="text-[11px] text-red-700">не отправлено: {{ $m['error'] }}</div>@endif
                                            @if(($m['kind'] ?? null) === 'letter')
                                                <div x-data="{ full: false }">
                                                    <div class="whitespace-pre-wrap" x-show="! full">{{ \Illuminate\Support\Str::limit($m['text'], 400) }}</div>
                                                    <div class="whitespace-pre-wrap" x-show="full" x-cloak>{{ $m['text'] }}</div>
                                                    @if(mb_strlen((string) $m['text']) > 400)<button type="button" @click="full = ! full" class="text-[11px] text-sky-700 hover:underline" x-text="full ? 'свернуть' : 'весь текст'"></button>@endif
                                                </div>
                                            @else
                                                <div class="whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($m['text'], 1500) }}</div>
                                            @endif
                                            @if(! empty($m['files']))
                                                @php
                                                    // Фото письма — галерея в просмотрщике (partials.image-lightbox), прочие файлы — ссылкой.
                                                    $isImage = fn ($f) => in_array($f['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
                                                    $fileUrl = fn ($f) => route('quoteparts.rfq-file', [$rfq->remote_id, $f['message_id'], $f['index']]);
                                                    $gallery = collect($m['files'])->filter($isImage)->values()->map(fn ($f) => ['src' => $fileUrl($f), 'name' => $f['name'], 'dl' => $fileUrl($f)])->all();
                                                    $shot = 0;
                                                @endphp
                                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                    @foreach($m['files'] as $f)
                                                        @if($isImage($f))
                                                            <a href="{{ $fileUrl($f) }}" title="{{ $f['name'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $gallery, 'index' => $shot++]) }})" class="block h-16 w-16 overflow-hidden rounded border border-border bg-surface cursor-zoom-in"><img src="{{ $fileUrl($f) }}" alt="" class="h-full w-full object-cover" loading="lazy"></a>
                                                        @else
                                                            <a href="{{ $fileUrl($f) }}" target="_blank" class="chip chip-neutral max-w-[260px] truncate">📎 {{ $f['name'] }}</a>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if($canAnswer)
                                        <textarea wire:model="qpAnswers.{{ $rfq->id }}.{{ $si }}" rows="3" placeholder="Answer in the supplier's language…"
                                                  class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                                        <div class="flex items-center gap-2">
                                            <button type="button" wire:click="answerQuotePartsSupplier({{ $rfq->id }}, {{ $si }})" wire:loading.attr="disabled" wire:target="answerQuotePartsSupplier"
                                                    class="btn btn-sm btn-primary">Отправить поставщику</button>
                                            <span class="text-[11px] text-fg-4">уйдёт письмом от MyZip в переписку поставщика; позиции «вопрос к нам» снова ждут цен</span>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>
