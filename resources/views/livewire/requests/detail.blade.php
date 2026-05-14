@php
    use App\Enums\MailDirection;
    use App\Enums\RequestStatus;
    use Illuminate\Support\Str;

    $req = $this->request;
    $email = $req->emailMessage;          // trigger-email (для Hero / Сводки)
    $thread = $this->thread;              // полный тред: trigger + reply'и (Phase 1.9 inbound)
    $items = $req->items;
    $assignments = $req->assignments;
    $tabs = $this->tabs;

    // Image-attachment detection: по mime_type и по расширению файла (часть писем
    // приходит без mime, тогда орбитим по расширению).
    $isImageAttachment = function ($a) {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff'];
        if ($a->mime_type && Str::startsWith(strtolower($a->mime_type), 'image/')) {
            return true;
        }
        $ext = strtolower(Str::afterLast($a->filename, '.'));
        return in_array($ext, $imageExtensions, true);
    };

    // Phase 1.10: chipClass через enum-метод (полный набор статусов из Foundation §5.2).
    $statusChip = $req->status->chipClass();
    $age = $req->created_at?->diffForHumans(['short' => true, 'parts' => 2]) ?? '—';
    $managerInitials = \Illuminate\Support\Str::of($req->assignedUser?->name ?? '?')
        ->substr(0, 1)->upper();

    $titleSuffix = $items->count() > 0
        ? ' · ' . $items->count() . ' ' . match (true) {
            $items->count() % 100 >= 11 && $items->count() % 100 <= 14 => 'позиций',
            $items->count() % 10 === 1 => 'позиция',
            $items->count() % 10 >= 2 && $items->count() % 10 <= 4 => 'позиции',
            default => 'позиций',
        }
        : '';
@endphp

<div class="max-w-[1320px] mx-auto px-6 pt-3 pb-8">

    {{-- ────────── SUBNAV ────────── --}}
    <div class="flex items-center gap-3 mb-3 text-[12.5px]">
        <a href="{{ route('requests.index') }}"
           class="inline-flex items-center gap-1.5 px-2.5 py-1 border border-border rounded-md bg-surface text-sky-700 hover:bg-hover transition-colors">
            ← К списку
        </a>
        <span class="uppercase tracking-wider text-[11.5px] text-fg-3 flex items-center gap-2">
            <span>Заявки</span>
            <span class="text-border-strong">/</span>
            <span class="text-fg-1 font-medium mono">{{ $req->internal_code }}</span>
        </span>
    </div>

    {{-- ────────── AI BANNER (Foundation §6.2 Phase E.2) ──────────
         Виден всегда (любая вкладка) когда есть pending enrichment
         suggestions. Inline-фраза с распознанными значениями + цитата.
         Кнопка «Применить всё → в работу» — только в AwaitingClient.
         «Открыть позиции →» — только если не на этом табе. --}}
    @php
        $_aiSuggs = collect();
        foreach ($req->items as $_i) {
            if (! $_i->is_active) continue;
            $_sgs = is_array($_i->quality_assessment_payload['enrichment_suggestions'] ?? null)
                ? $_i->quality_assessment_payload['enrichment_suggestions'] : [];
            foreach ($_sgs as $_sg) {
                if (is_array($_sg) && ($_sg['status'] ?? 'pending') === 'pending') {
                    $_aiSuggs->push(['item' => $_i, 'sugg' => $_sg]);
                }
            }
        }
        $_aiPositionsCount = $_aiSuggs->groupBy(fn ($e) => $e['item']->id)->count();
        $_aiAvgConf = $_aiSuggs->isNotEmpty()
            ? (int) round($_aiSuggs->avg(fn ($e) => (float) ($e['sugg']['confidence'] ?? 0)) * 100) : 0;

        // Распознанные пары «глагол значение» с inline-форматированием
        // (значение mono+bold). Глагол per-field, чтобы фраза читалась.
        $_aiFieldVerbs = [
            'parsed_brand' => 'бренд',
            'parsed_article' => 'подтверждение артикула',
            'parsed_qty' => 'количество',
        ];
        $_aiSummaryPairs = [];
        foreach ($_aiSuggs->take(4) as $_e) {
            $_f = (string) ($_e['sugg']['field'] ?? '');
            $_v = (string) ($_e['sugg']['value'] ?? '');
            if ($_v === '') continue;
            if (isset($_aiFieldVerbs[$_f])) {
                $_aiSummaryPairs[] = [$_aiFieldVerbs[$_f], $_v];
            } elseif (str_starts_with($_f, 'kb:')) {
                $_kbSlug = substr($_f, 3);
                $_aiSlots = isset($slotResolver) ? $slotResolver->resolve($_e['item'])
                    : app(\App\Services\Kb\PositionSlotResolver::class)->resolve($_e['item']);
                $_kbLabel = collect($_aiSlots)->firstWhere('key', $_f)['label'] ?? $_kbSlug;
                $_aiSummaryPairs[] = [mb_strtolower($_kbLabel), $_v];
            }
        }

        // Sample citation — берём первый непустой quote.
        $_aiQuote = '';
        foreach ($_aiSuggs as $_e) {
            $_q = trim((string) ($_e['sugg']['source_quote'] ?? ''));
            if ($_q !== '') { $_aiQuote = $_q; break; }
        }

        $_inClarif = $req->status === \App\Enums\RequestStatus::AwaitingClientClarification;

        // Рекомендация — action-цепочка. Возвращаем массив [verb, ?highlight_label]
        // чтобы шаблон мог визуально выделить статус-пилюлю.
        $_aiRecs = [];
        if ($_inClarif) {
            $_aiRecs[] = ['перевести в статус ', 'в работе'];
        }
        $_aiRecs[] = ['применить ' . $_aiSuggs->count() . ' '
            . \Illuminate\Support\Str::plural('уточнение', $_aiSuggs->count()), null];
        $_aiRecs[] = ['отправить КП', null];

        // $canEditItems определена внутри @case('items'), здесь баннер
        // отрисовывается ВНЕ tabs — вычисляем локально.
        $_canEdit = auth()->id() === $req->assigned_user_id
            || auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary']);

        // «Открыть позиции →» только если мы не на items-табе.
        $_onItemsTab = $tab === 'items';
    @endphp
    @if($_aiSuggs->isNotEmpty() && $_canEdit && ! $aiBannerHidden)
        {{-- Layout по макету 04c: grid auto/1fr/auto, мягкий gradient
             violet → surface, AI-плашка 36×36, conf-чип в mono pill,
             body — flex wrap c inline-цитатой и рекомендацией. --}}
        <div class="grid items-center gap-4 mb-3.5 px-[18px] py-3.5 rounded-md border shadow-sm"
             style="grid-template-columns: auto 1fr auto;
                    background: linear-gradient(180deg, oklch(97% 0.03 280) 0%, var(--bg-surface) 100%);
                    border-color: oklch(82% 0.10 280)">
            {{-- AI icon — 40×40, насыщенный violet, лёгкая тень для объёма --}}
            <span class="w-10 h-10 rounded-[10px] flex items-center justify-center text-white font-bold text-[17px] leading-none shadow-sm"
                  style="background: oklch(54% 0.22 280)">AI</span>

            {{-- Content column --}}
            <div class="min-w-0">
                {{-- Title + confidence pill --}}
                <h3 class="m-0 font-semibold text-[13.5px] text-fg-1" style="line-height:1.3">
                    Клиент ответил на уточнение по {{ $_aiPositionsCount }} {{ \Illuminate\Support\Str::plural('позиц', $_aiPositionsCount) }}{{ $_aiPositionsCount === 1 ? 'и' : ($_aiPositionsCount < 5 ? 'ям' : 'иям') }}
                    <span class="inline-flex items-baseline ml-2 px-1.5 py-0.5 rounded-full mono text-[11.5px] font-semibold leading-none bg-surface border"
                          style="border-color: oklch(86% 0.08 280); color: oklch(46% 0.16 280)">
                        уверенность {{ $_aiAvgConf }}%
                    </span>
                </h3>

                {{-- Body — единый flex-wrap с inline-цитатой и рекомендацией --}}
                <div class="mt-1 text-[12.5px] text-fg-2 flex items-center gap-2 flex-wrap" style="line-height:1.45">
                    @if(! empty($_aiSummaryPairs))
                        <span>
                            Распознал
                            @foreach($_aiSummaryPairs as $_idx => $_pair)
                                {{ $_pair[0] }} <b class="text-fg-1 font-medium">{{ $_pair[1] }}</b>{{ $_idx < count($_aiSummaryPairs) - 2 ? ', ' : ($_idx === count($_aiSummaryPairs) - 2 ? ' и ' : '') }}
                            @endforeach
                            @if($_aiQuote !== '') . Цитата из ответа:@else .@endif
                        </span>
                    @endif
                    @if($_aiQuote !== '')
                        <span class="px-1.5 py-0.5 rounded-sm bg-surface border border-border mono text-fg-2 text-[11.5px]">«{{ \Illuminate\Support\Str::limit($_aiQuote, 200) }}»</span>
                    @endif
                    @if(! empty($_aiRecs))
                        <span>
                            Рекомендую:
                            @foreach($_aiRecs as $_idx => $_rec)
                                {{ $_rec[0] }}@if($_rec[1] !== null)<b class="text-fg-1 font-medium">«{{ $_rec[1] }}»</b>@endif{{ $_idx < count($_aiRecs) - 1 ? ', ' : '.' }}
                            @endforeach
                        </span>
                    @endif
                </div>
            </div>

            {{-- Actions column --}}
            <div class="flex items-center gap-1.5">
                <button type="button"
                        wire:click="hideAiBanner"
                        class="btn btn-sm">Скрыть</button>
                @if(! $_onItemsTab)
                    <a href="#" wire:click.prevent="setTab('items')"
                       class="text-[12px] text-sky-700 hover:underline px-1">Открыть позиции →</a>
                @endif
                @if($_inClarif)
                    <button type="button"
                            wire:click="applyAllAndProgress"
                            wire:confirm="Применить все {{ $_aiSuggs->count() }} предложений и перевести заявку в «В работе»?"
                            class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="applyAllAndProgress">
                        <span wire:loading.remove wire:target="applyAllAndProgress">Применить всё и → в работу</span>
                        <span wire:loading wire:target="applyAllAndProgress">…</span>
                    </button>
                @else
                    <button type="button"
                            wire:click="applyAllEnrichments"
                            wire:confirm="Применить все {{ $_aiSuggs->count() }} предложений?"
                            class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="applyAllEnrichments">
                        <span wire:loading.remove wire:target="applyAllEnrichments">Применить всё</span>
                        <span wire:loading wire:target="applyAllEnrichments">…</span>
                    </button>
                @endif
            </div>
        </div>
    @endif

    {{-- ────────── HERO ────────── --}}
    <div class="ds-card p-[18px] mb-4 grid gap-4" style="grid-template-columns: 1fr auto">

        <div class="min-w-0">
            {{-- ID row --}}
            <div class="flex items-center gap-2.5 mb-1.5 text-[12px] mono text-fg-3">
                <span class="text-fg-2 font-medium">#{{ $req->internal_code }}</span>
                <button type="button"
                        class="text-fg-3 border border-border px-1.5 py-0.5 rounded text-[10.5px] uppercase tracking-wider hover:bg-hover"
                        onclick="navigator.clipboard.writeText('{{ $req->internal_code }}'); this.textContent='скопировано';">
                    копировать
                </button>
                <span class="text-border-strong">·</span>
                <span>создано {{ $req->created_at?->format('d.m.Y H:i') ?? '—' }}</span>
                @if($req->updated_at && $req->updated_at->ne($req->created_at))
                    <span class="text-border-strong">·</span>
                    <span>обновлено {{ $req->updated_at->format('d.m.Y H:i') }}</span>
                @endif
            </div>

            {{-- Title --}}
            <h1 class="text-[22px] leading-tight font-semibold text-fg-1 mb-1.5"
                style="letter-spacing: -0.005em">
                {{ $req->subject ?: '(без темы)' }}{{ $titleSuffix }}
            </h1>

            {{-- Sub: client / email / mailbox --}}
            <div class="flex items-center gap-2 flex-wrap text-[13px] text-fg-3">
                @if($req->client_name)
                    <span class="text-fg-1 font-medium">{{ $req->client_name }}</span>
                    <span class="text-border-strong">·</span>
                @endif
                @if($req->client_email)
                    <a href="mailto:{{ $req->client_email }}" class="text-sky-700 hover:underline">{{ $req->client_email }}</a>
                @endif
                @if($email?->mailbox)
                    <span class="text-border-strong">·</span>
                    <span>через <span class="text-fg-1">{{ $email->mailbox->email }}</span></span>
                @endif
            </div>

            {{-- Status row --}}
            <div class="mt-3.5 pt-3.5 border-t border-border-subtle flex items-center gap-3.5 flex-wrap text-[12.5px]">
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Статус</span>
                    <span class="inline-flex items-center gap-1.5 flex-wrap">
                        <span class="chip {{ $statusChip }}"><span class="dot"></span>{{ $req->status->label() }}</span>
                        @if(($req->reanimated_count ?? 0) > 0 && $req->reanimated_at)
                            @php
                                $daysSinceReanimate = (int) abs(now()->diffInDays($req->reanimated_at, false));
                                $reanimateTooltip = 'Реанимирована ' . $req->reanimated_at->format('d.m.Y H:i')
                                    . ' · циклов: ' . $req->reanimated_count;
                            @endphp
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm bg-violet-50 text-violet-700 text-[10.5px] font-semibold uppercase tracking-wider"
                                  title="{{ $reanimateTooltip }}">
                                ↻ реанимирована
                                @if($req->reanimated_count > 1)
                                    <span class="opacity-75">×{{ $req->reanimated_count }}</span>
                                @endif
                                <span class="opacity-75 normal-case font-normal">· {{ $daysSinceReanimate }} дн.</span>
                            </span>
                        @endif
                    </span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">SLA</span>
                    <span class="text-fg-3" title="Доступно в Phase 2">—</span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Менеджер</span>
                    <span class="text-fg-1 inline-flex items-center gap-1.5 flex-wrap">
                        @if($req->assignedUser)
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-neutral-200 text-fg-2 text-[9.5px] font-semibold">{{ $managerInitials }}</span>
                            {{ $req->assignedUser->name }}
                        @else
                            <span class="text-fg-3">— не назначен —</span>
                        @endif
                        {{-- Phase 2 delegation: badge acting'а если есть. --}}
                        @php
                            $activeDel = $req->activeDelegations()->with('actingUser:id,name')->first();
                        @endphp
                        @if($activeDel)
                            <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-700 font-semibold text-[10.5px]"
                                  title="Открыто {{ $activeDel->actingUser?->name }} на время отсутствия владельца{{ $req->assignedUser?->unavailable_until ? ' до '.$req->assignedUser->unavailable_until->format('d.m.Y') : '' }}">
                                ↺ acting: {{ $activeDel->actingUser?->name }}
                            </span>
                        @endif
                    </span>
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle min-w-0">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Sticky</span>
                    @php $sticky = $this->sticky; @endphp
                    @if($sticky['links']->isNotEmpty())
                        <span class="flex items-center gap-1.5 flex-wrap"
                              title="Заявки, по которым AssignmentService прицепил эту через совпадение артикула или названия позиции">
                            @foreach($sticky['links'] as $linked)
                                {{-- Без wire:navigate — после SPA-перехода между
                                     двумя Detail-страницами Livewire не пересоздаёт
                                     state и вкладки/диалоги перестают реагировать.
                                     Full reload надёжнее (минусом — 200-300мс лаг). --}}
                                <a href="{{ route('requests.show', $linked) }}"
                                   class="mono text-[12px] text-sky-700 hover:underline">{{ $linked->internal_code }}</a>{{ ! $loop->last ? ',' : '' }}
                            @endforeach
                        </span>
                    @elseif($sticky['legacy'])
                        <span class="text-fg-2 text-[12px]"
                              title="Старая запись (до Phase 2 sticky visibility) — детали привязки не сохранены">sticky</span>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </div>
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Возраст</span>
                    <span class="text-fg-1 mono">{{ $age }}</span>
                </div>
                @php
                    // Phase 2 use-case C: hero «Сумма» и «Сматчено» считаем по items.catalogItem.
                    // Сумма — сумма (qty × catalog.price) по всем item'ам с привязкой
                    // (item не сматчен → не в сумме). НДС НЕ добавляем — это «netto»
                    // подытог по каталожным ценам, ту же сумму видно внизу таба
                    // «Позиции». Итог с НДС там же.
                    $heroSubtotal = 0.0;
                    $heroMatched = 0;
                    foreach ($items as $itm) {
                        $p = $itm->catalogItem?->price;
                        $q = (float) ($itm->parsed_qty ?? 0);
                        if ($p !== null && $q > 0) {
                            $heroSubtotal += (float) $p * $q;
                            $heroMatched++;
                        }
                    }
                    $heroTotal = $items->count();
                    $heroHasMoney = $heroSubtotal > 0;
                    $heroMatchedPct = $heroTotal > 0 ? (int) round($heroMatched / $heroTotal * 100) : 0;
                @endphp
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сумма</span>
                    <span class="{{ $heroHasMoney ? 'text-fg-1' : 'text-fg-3' }} mono"
                          title="Подытог по каталожным ценам сматченных позиций (без НДС). С НДС см. внизу таба «Позиции».">
                        {{ $heroHasMoney ? number_format($heroSubtotal, 2, '.', ' ') . ' ₽' : '—' }}
                    </span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сматчено</span>
                    @if($heroTotal === 0)
                        <span class="text-fg-3">—</span>
                    @else
                        <span class="text-fg-1"
                              title="Позиций с привязкой к каталогу. На остальных оператор видит только данные парсера.">
                            {{ $heroMatched }} / {{ $heroTotal }}
                            <span class="text-fg-3 text-[11.5px]">({{ $heroMatchedPct }}%)</span>
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Actions --}}
        @php
            // Phase 1.10 — действия зависят от текущего статуса.
            // Foundation Фаза 2: acting (active delegation) тоже может управлять.
            $authUser = auth()->user();
            $isOwner = $authUser && $req->assigned_user_id === $authUser->id;
            $isDelegate = $authUser && $req->isDelegatedTo($authUser);
            $canManage = $isOwner || $isDelegate
                || $authUser?->hasAnyRole(['head_of_sales', 'director']);
            // Отвечать клиенту — owner или acting (но не РОП без явной делегации
            // — он не должен «случайно» писать клиенту от чужого имени).
            $canReply = $isOwner || $isDelegate;
            $canReassign = $authUser?->hasAnyRole(['head_of_sales', 'director', 'secretary']);
            $lastInbound = $thread->reverse()
                ->first(fn ($m) => $m->direction === \App\Enums\MailDirection::Inbound);
            $allowed = $req->status->allowedTransitions();
            $allow = fn (\App\Enums\RequestStatus $t) => in_array($t, $allowed, true);
            $RS = \App\Enums\RequestStatus::class;
        @endphp
        <div class="flex flex-col gap-2 min-w-[200px]">

            {{-- Phase 4 (Foundation §7): pending AI-suggestion'ы DocumentDetector'а.
                 Рендерятся ВЫШЕ action-panel чтобы оператор увидел и принял
                 решение до основных кнопок переходов.
                 Phase E.2: inbound_clarification_response теперь показывается
                 отдельным баннером вверху страницы (детализированно с diff/bar),
                 поэтому здесь отфильтровываем — не дублируем. --}}
            @php
                $aiSuggestions = $this->pendingAiDecisions
                    ->reject(fn ($s) => ($s->detector_type->value ?? null) === 'inbound_clarification_response');
            @endphp
            @if($aiSuggestions->isNotEmpty() && $canManage)
                @foreach($aiSuggestions as $sugg)
                    @php
                        $sType = $sugg->detector_type;
                        $sTarget = $sType->targetStatus();
                        $sConf = (int) round(($sugg->confidence ?? 0) * 100);
                        $sIcon = match($sType->value) {
                            'outbound_quotation_full', 'outbound_quotation_partial' => '📨',
                            'outbound_invoice' => '🧾',
                            'outbound_clarification' => '❓',
                            'inbound_under_review' => '📑',
                            'inbound_postponed' => '⏰',
                            'inbound_invoice_request' => '💵',
                            'inbound_decline' => '⊘',
                            'inbound_clarification_response' => '↩',
                            default => '🤖',
                        };
                    @endphp
                    @php
                        $sPayload = is_array($sugg->payload) ? $sugg->payload : [];
                        $sReasoning = $sPayload['reasoning'] ?? null;
                        $sQuote = $sPayload['cited_phrase'] ?? null;
                        $sResumeDate = $sPayload['suggested_resume_date'] ?? null;
                    @endphp
                    <div class="ds-card p-3 text-[12.5px] bg-[var(--sky-50)] border-[var(--sky-300)]"
                         wire:key="ai-{{ $sugg->id }}">
                        <div class="flex items-start gap-2 mb-2">
                            <span class="text-[18px] leading-none">{{ $sIcon }}</span>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-fg-1 text-[12.5px] leading-tight">
                                    AI: {{ $sType->label() }}
                                </div>
                                <div class="text-[11px] text-fg-3 mt-0.5">
                                    Уверенность {{ $sConf }}%
                                    @if($sTarget)
                                        · перевести в «{{ $sTarget->label() }}»
                                    @endif
                                    @if($sResumeDate)
                                        · до {{ \Illuminate\Support\Carbon::parse($sResumeDate)->format('d.m.Y') }}
                                    @endif
                                </div>
                                @if($sQuote)
                                    <div class="text-[11.5px] text-fg-2 mt-1.5 pl-2 border-l-2 border-[var(--sky-400)] italic">
                                        «{{ \Illuminate\Support\Str::limit($sQuote, 180) }}»
                                    </div>
                                @endif
                                @if($sReasoning && ! $sQuote)
                                    <div class="text-[11px] text-fg-3 mt-1">{{ \Illuminate\Support\Str::limit($sReasoning, 140) }}</div>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            @if($sTarget && $allow($sTarget))
                                <button type="button"
                                        wire:click="applyAiDecision({{ $sugg->id }})"
                                        class="btn btn-sm btn-primary flex-1">
                                    ✓ Применить
                                </button>
                            @else
                                <button type="button" class="btn btn-sm flex-1" disabled
                                        title="Переход не разрешён из текущего статуса">✓ Применить</button>
                            @endif
                            <button type="button"
                                    wire:click="dismissAiDecision({{ $sugg->id }})"
                                    class="btn btn-sm">
                                ✕
                            </button>
                        </div>
                    </div>
                @endforeach
            @endif

            {{-- Terminal: только информационная плашка. --}}
            @if($req->status->isTerminal())
                <div class="ds-card p-3 text-[12.5px] {{ $req->status === $RS::ClosedWon ? 'bg-emerald-50 border-emerald-300' : 'bg-red-50 border-red-300' }}">
                    <div class="font-medium text-fg-1 mb-1">
                        {{ $req->status === $RS::ClosedWon ? '✓ Заявка закрыта · успех' : '⊘ Заявка закрыта · потеря' }}
                    </div>
                    @if($req->closed_at)
                        <div class="text-fg-3 text-[11.5px]">{{ $req->closed_at->format('d.m.Y H:i') }}</div>
                    @endif
                    @if($req->closed_lost_reason)
                        @php
                            $lostReasonEnum = \App\Enums\ClosedLostReason::tryFrom($req->closed_lost_reason);
                        @endphp
                        <div class="text-fg-2 mt-1.5">
                            <span class="text-fg-3">Причина:</span> {{ $lostReasonEnum?->label() ?? $req->closed_lost_reason }}
                        </div>
                        @if($req->closed_lost_comment)
                            <div class="text-fg-2 text-[11.5px] mt-1 whitespace-pre-wrap">{{ $req->closed_lost_comment }}</div>
                        @endif
                    @endif
                </div>
                @if($canReassign)
                    <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" />
                @endif

            {{-- Paused: единственная кнопка «снять с паузы». --}}
            @elseif($req->status === $RS::Paused)
                <div class="ds-card p-3 text-[12.5px] bg-neutral-50 border-neutral-300">
                    <div class="font-medium text-fg-1">⏸ Заявка на паузе</div>
                    @if($req->paused_until)
                        <div class="text-fg-3 text-[11.5px] mt-1">
                            Авто-возврат {{ $req->paused_until->format('d.m.Y') }}
                            @if($req->paused_from_status)
                                · в «{{ ($RS::tryFrom($req->paused_from_status))?->label() ?? $req->paused_from_status }}»
                            @endif
                        </div>
                    @endif
                    @if($req->paused_reason)
                        <div class="text-fg-2 mt-1.5 whitespace-pre-wrap">{{ $req->paused_reason }}</div>
                    @endif
                </div>
                @if($canManage)
                    <button type="button" wire:click="resumeFromPause"
                            class="btn btn-primary"
                            wire:confirm="Снять заявку с паузы прямо сейчас?">
                        ▶ Снять с паузы вручную
                    </button>
                @endif
                @if($canReassign)
                    <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" />
                @endif

            {{-- Активные статусы. --}}
            @else
                {{-- Главные действия зависят от статуса. --}}
                @if($allow($RS::InProgress) && $req->status !== $RS::InProgress)
                    <button type="button" wire:click="transitionStatus('in_progress')"
                            class="btn btn-primary"
                            @disabled(! $canManage)>
                        @if($req->status === $RS::AwaitingClientClarification)
                            ✓ Клиент ответил
                        @elseif($req->status === $RS::UnderReview || $req->status === $RS::PostponedUntil || $req->status === $RS::Quoted)
                            ↩ Вернуться к работе
                        @else
                            ▶ Начать работу
                        @endif
                    </button>
                @endif

                @if($allow($RS::Quoted))
                    <button type="button" wire:click="transitionStatus('quoted')"
                            class="btn"
                            @disabled(! $canManage)>📨 КП отправлено</button>
                @endif

                @if($allow($RS::AwaitingClientClarification))
                    <button type="button" wire:click="transitionStatus('awaiting_client_clarification')"
                            class="btn"
                            @disabled(! $canManage)>❓ Жду уточнение клиента</button>
                @endif

                @if($allow($RS::UnderReview))
                    <button type="button" wire:click="transitionStatus('under_review')"
                            class="btn btn-sm"
                            @disabled(! $canManage)>📑 Клиент на согласовании</button>
                @endif

                @if($allow($RS::PostponedUntil))
                    <button type="button" wire:click="$dispatch('open-postpone-dialog')"
                            class="btn btn-sm"
                            @disabled(! $canManage)>⏰ Клиент отложил</button>
                @endif

                @if($allow($RS::AwaitingInvoice))
                    <button type="button" wire:click="transitionStatus('awaiting_invoice')"
                            class="btn btn-sm"
                            @disabled(! $canManage)>💵 Запросил счёт</button>
                @endif

                @if($allow($RS::Invoiced))
                    <button type="button" wire:click="transitionStatus('invoiced')"
                            class="btn btn-sm"
                            @disabled(! $canManage)>💴 Счёт отправлен</button>
                @endif

                @if($allow($RS::Paid))
                    <button type="button" wire:click="transitionStatus('paid')"
                            class="btn btn-sm"
                            @disabled(! $canManage)>💰 Оплачено</button>
                @endif

                @if($allow($RS::ClosedWon))
                    <button type="button" wire:click="transitionStatus('closed_won')"
                            class="btn btn-sm"
                            wire:confirm="Закрыть заявку как успешно завершённую?"
                            @disabled(! $canManage)>✓ Закрыть как успех</button>
                @endif

                {{-- Ответить — отдельной строкой (Phase 1.9).
                     Переключает таб на «Переписка» (где зарегистрирован
                     ComposeForm) И диспатчит open-reply одной операцией. --}}
                <div class="flex gap-1.5">
                    @if($canReply)
                        <button type="button"
                                class="btn flex-1"
                                wire:click="composeReply({{ $lastInbound?->id ?: 'null' }})"
                        >✉ Ответить</button>
                    @else
                        <button class="btn flex-1" disabled title="Отвечать может только назначенный менеджер или acting на время отсутствия">✉ Ответить</button>
                    @endif
                </div>

                {{-- Пауза + Переподчинить — компактно. --}}
                <div class="flex gap-1.5">
                    @if($req->status->canBePaused() && $canManage)
                        <button type="button"
                                wire:click="$dispatch('open-pause-dialog')"
                                class="btn btn-sm flex-1">⏸ Пауза</button>
                    @else
                        <button class="btn btn-sm flex-1" disabled>⏸ Пауза</button>
                    @endif
                    @if($canReassign)
                        <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" />
                    @else
                        <button class="btn btn-sm flex-1" disabled title="Только РОП/директор/секретарь">⊘ Переподчинить</button>
                    @endif
                </div>

                @if($allow($RS::ClosedLost))
                    <button type="button"
                            wire:click="$dispatch('open-close-lost-dialog')"
                            class="btn btn-sm btn-danger"
                            @disabled(! $canManage)>⊘ Закрыть как потеря</button>
                @endif

                {{-- Disabled buttons (для напоминания — Phase 2/3). --}}
                <button class="btn btn-sm" disabled title="Доступно в Phase 2">🧾 Сформировать КП</button>
                <button class="btn btn-sm" disabled title="Доступно в Phase 3">🔄 Refresh цен (поставщики)</button>
            @endif

            {{-- Модальные диалоги (single-instance per Detail). --}}
            <livewire:requests.pause-dialog :request="$req" wire:key="pause-{{ $req->id }}" />
            <livewire:requests.postpone-dialog :request="$req" wire:key="postpone-{{ $req->id }}" />
            <livewire:requests.close-lost-dialog :request="$req" wire:key="close-lost-{{ $req->id }}" />
        </div>
    </div>

    {{-- ────────── TABS ────────── --}}
    <div class="flex border border-border bg-surface px-4 rounded-t-md" style="border-bottom-color: var(--border-subtle)">
        @foreach($tabs as $key => $meta)
            @php $active = $tab === $key; @endphp
            <button type="button"
                    @if(! $meta['disabled']) wire:click="setTab('{{ $key }}')" @else disabled title="Доступно в Phase 2" @endif
                    class="-mb-px px-3.5 py-2.5 text-[12.5px] inline-flex items-center gap-1.5 border-b-2 transition-colors
                           {{ $active
                              ? 'text-fg-1 font-semibold border-accent'
                              : 'text-fg-3 border-transparent ' . ($meta['disabled'] ? 'opacity-55 cursor-not-allowed' : 'hover:text-fg-1 cursor-pointer') }}">
                {{ $meta['label'] }}
                @if($meta['count'] !== null)
                    <span class="text-[10.5px] font-semibold px-1.5 rounded-full
                                 {{ $active ? 'bg-red-50 text-red-700' : 'bg-neutral-100 text-fg-2' }}">
                        {{ $meta['count'] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ────────── TAB PANEL ────────── --}}
    <div class="border border-border border-t-0 rounded-b-md bg-surface-2 p-3.5">

        @switch($tab)

            {{-- ───── ОБЗОР ───── --}}
            @case('overview')
                <div class="grid gap-4" style="grid-template-columns: 1.55fr 1fr">

                    {{-- Left: Позиции preview + Переписка preview --}}
                    <div class="space-y-4">
                        <div class="ds-card">
                            <div class="ds-card-header">
                                <h3>Позиции запроса</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $items->count() }}</span>
                                <span class="flex-1"></span>
                                <button type="button" wire:click="setTab('items')" class="text-sky-700 text-xs hover:underline">все позиции →</button>
                            </div>
                            <div class="ds-card-body p-0">
                                @if($items->isEmpty())
                                    <div class="px-[18px] py-6 text-center text-fg-3 text-sm">
                                        Парсер позиций ещё не отработал.
                                        <div class="text-[11.5px] mt-1 text-fg-4">Задача в очереди — обновите страницу через минуту.</div>
                                    </div>
                                @else
                                    <div class="divide-y divide-[var(--border-subtle)]">
                                        @foreach($items->take(5) as $item)
                                            <div class="px-[18px] py-2.5 flex items-start gap-3 text-[12.5px]">
                                                <span class="mono text-fg-3 w-5 text-right">{{ $item->position }}</span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="font-medium text-fg-1 truncate">{{ $item->parsed_name }}</div>
                                                    <div class="text-[11.5px] text-fg-3 flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                                        @if($item->parsed_brand)<span>{{ $item->parsed_brand }}</span>@endif
                                                        @if($item->parsed_article)<span class="text-border-strong">·</span><span class="mono">{{ $item->parsed_article }}</span>@endif
                                                        @if($item->supplier_note)<span class="text-border-strong">·</span><span>{{ \Illuminate\Support\Str::limit($item->supplier_note, 60) }}</span>@endif
                                                    </div>
                                                </div>
                                                <span class="mono text-fg-1 whitespace-nowrap">{{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') ?: '—' }} {{ $item->parsed_unit }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header">
                                <h3>Переписка</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['thread']['count'] }}</span>
                                <span class="flex-1"></span>
                                <button type="button" wire:click="setTab('thread')" class="text-sky-700 text-xs hover:underline">открыть тред →</button>
                            </div>
                            <div class="ds-card-body">
                                @if($email)
                                    <div class="text-[12.5px] text-fg-3">
                                        <span class="text-fg-1 font-medium">{{ $email->from_name ?: $email->from_email }}</span>
                                        · <span class="mono">{{ $email->sent_at?->format('d.m.Y H:i') ?? '—' }}</span>
                                    </div>
                                    <div class="mt-1.5 text-sm text-fg-1 line-clamp-3">
                                        {{ \Illuminate\Support\Str::limit(strip_tags($email->body_plain ?: $email->body_html ?: ''), 320) }}
                                    </div>
                                @else
                                    <div class="text-fg-3 text-sm">Заявка создана не из e-mail (нет переписки).</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Right: Сводка / Refresh / Sticky / Активность --}}
                    <div class="space-y-4">
                        <div class="ds-card">
                            <div class="ds-card-header"><h3>Сводка</h3></div>
                            <div class="ds-card-body">
                                <dl class="grid gap-y-2 gap-x-2.5 text-[13px]" style="grid-template-columns: 110px 1fr">
                                    <dt class="text-fg-3">Канал</dt>
                                    <dd class="text-fg-1 font-medium">
                                        @if($email?->mailbox)
                                            Я.Почта · {{ $email->mailbox->email }}
                                            @if($email->message_id)
                                                <span class="block mt-0.5 text-[11.5px] text-fg-3 font-normal mono truncate">{{ $email->message_id }}</span>
                                            @endif
                                        @else
                                            <span class="text-fg-3 font-normal">—</span>
                                        @endif
                                    </dd>
                                    <dt class="text-fg-3">Источник</dt>
                                    <dd class="text-fg-1 font-medium">{{ $email ? 'Прямое письмо клиента' : 'Не e-mail' }}</dd>
                                    <dt class="text-fg-3">Категория AI</dt>
                                    <dd class="text-fg-1 font-medium">
                                        @if($email?->category)
                                            {{ \App\Enums\EmailCategory::tryFrom($email->category)?->label() ?? $email->category }}
                                            @if($email->category_confidence)
                                                <span class="text-[11.5px] text-fg-3 font-normal">· {{ round($email->category_confidence * 100) }}%</span>
                                            @endif
                                        @else
                                            <span class="text-fg-3 font-normal">не категоризировано</span>
                                        @endif
                                    </dd>
                                    <dt class="text-fg-3">ИНН клиента</dt>
                                    <dd class="text-fg-3 font-normal">— <span class="text-[11.5px]">(Phase 2)</span></dd>
                                    <dt class="text-fg-3">Договор</dt>
                                    <dd class="text-fg-3 font-normal">— <span class="text-[11.5px]">(Phase 2)</span></dd>
                                </dl>
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header"><h3>Refresh цен</h3></div>
                            <div class="ds-card-body text-sm text-fg-3">
                                Привязка поставщиков и refresh-цикл — Phase 2.
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header"><h3>Sticky-цепочка</h3></div>
                            <div class="ds-card-body text-sm text-fg-3">
                                История связей менеджер↔клиент — Phase 2.
                            </div>
                        </div>

                        <div class="ds-card">
                            <div class="ds-card-header">
                                <h3>Активность</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['activity']['count'] }}</span>
                                <span class="flex-1"></span>
                                <button type="button" wire:click="setTab('activity')" class="text-sky-700 text-xs hover:underline">все →</button>
                            </div>
                            <div class="ds-card-body">
                                <div class="relative pl-5 text-[12.5px]">
                                    <div class="absolute left-[5px] top-1.5 bottom-1.5 w-px bg-border-strong"></div>
                                    @foreach($assignments->take(5) as $a)
                                        <div class="relative py-1.5">
                                            <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-surface border-[1.5px] border-neutral-400"></span>
                                            <div class="text-fg-1 leading-snug">
                                                Назначен <b class="font-semibold">{{ $a->user?->name ?? '—' }}</b>
                                                @if($a->reason) · {{ $a->reason }} @endif
                                            </div>
                                            <div class="mono text-[11px] text-fg-3 mt-0.5">{{ $a->assigned_at?->format('d.m.Y H:i') }}@if($a->assignedBy) · {{ $a->assignedBy->name }} @endif</div>
                                        </div>
                                    @endforeach
                                    <div class="relative py-1.5">
                                        <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full bg-emerald-700 border-[1.5px] border-emerald-700"></span>
                                        <div class="text-fg-1 leading-snug">Заявка создана</div>
                                        <div class="mono text-[11px] text-fg-3 mt-0.5">{{ $req->created_at?->format('d.m.Y H:i') }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @break

            {{-- ───── ПЕРЕПИСКА ───── --}}
            @case('thread')
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Переписка</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $tabs['thread']['count'] }}</span>
                        <span class="flex-1"></span>
                        @if($email?->mailbox)
                            <span class="text-[11.5px] text-fg-3">канал: {{ $email->mailbox->email }} ↔ {{ $req->client_email }}</span>
                        @endif
                    </div>

                    @if($thread->isEmpty())
                        <div class="ds-card-body text-sm text-fg-3">Заявка создана не из e-mail.</div>
                    @else
                        <div>
                            @foreach($thread as $msg)
                                @php
                                    $isOutbound = $msg->direction === MailDirection::Outbound;
                                    $authorName = $msg->from_name ?: $msg->from_email;
                                    $authorInitial = \Illuminate\Support\Str::of($authorName)->substr(0, 1)->upper();
                                    $catLabel = $msg->category
                                        ? (\App\Enums\EmailCategory::tryFrom($msg->category)?->label() ?? $msg->category)
                                        : null;
                                @endphp
                                <div class="border-b border-border-subtle last:border-b-0">
                                    <div class="flex items-center gap-2.5 px-[18px] py-3">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-[11px] font-semibold shrink-0
                                                     {{ $isOutbound ? 'bg-accent text-fg-on-accent' : 'bg-neutral-200 text-fg-2' }}">
                                            {{ $authorInitial }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-[13px] text-fg-1 truncate">
                                                {{ $authorName }}
                                            </div>
                                            <div class="text-[11.5px] text-fg-3 mono truncate">
                                                {{ $msg->from_email }} · {{ $msg->sent_at?->format('d.m.Y в H:i') ?? '—' }}
                                                @if($msg->mailbox)
                                                    · через {{ $msg->mailbox->email }}
                                                @endif
                                            </div>
                                        </div>
                                        <span class="flex-1"></span>
                                        @if($isOutbound)
                                            <span class="chip chip-info"><span class="dot"></span>исходящее</span>
                                        @elseif($catLabel)
                                            <span class="chip chip-ok"><span class="dot"></span>{{ $catLabel }}</span>
                                        @endif
                                    </div>
                                    <div class="px-[18px] pb-3.5 pl-[56px] text-[13px] leading-[1.55] text-fg-1">
                                        @php $html = $this->bodyHtmlFor($msg); @endphp
                                        @if($html)
                                            {{-- Письмо рендерится в sandbox-iframe (srcdoc), чтобы <style>-блоки
                                                 из тела письма не утекали на страницу и не ломали .btn / шрифты CRM.
                                                 sandbox без allow-scripts — JS из письма не выполнится. --}}
                                            <iframe
                                                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                                srcdoc="{{ $html }}"
                                                loading="lazy"
                                                class="w-full block border-0 bg-surface"
                                                style="height: 0"
                                                x-data
                                                x-init="
                                                    const fit = () => {
                                                        try {
                                                            const h = $el.contentDocument && $el.contentDocument.documentElement
                                                                ? $el.contentDocument.documentElement.scrollHeight
                                                                : 0;
                                                            $el.style.height = (h + 4) + 'px';
                                                        } catch (e) {}
                                                    };
                                                    $el.addEventListener('load', () => {
                                                        try {
                                                            const doc = $el.contentDocument;
                                                            if (!doc) return;
                                                            doc.querySelectorAll('a[href]').forEach(a => {
                                                                a.target = '_blank';
                                                                a.rel = 'noopener noreferrer';
                                                            });
                                                            const s = doc.createElement('style');
                                                            s.textContent = 'html,body{margin:0;padding:0}body{padding:8px 12px;font:13px/1.55 system-ui,-apple-system,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}';
                                                            (doc.head || doc.documentElement).appendChild(s);
                                                            try { new ResizeObserver(fit).observe(doc.documentElement); } catch (e) {}
                                                            fit();
                                                        } catch (e) {}
                                                    });
                                                "
                                            ></iframe>
                                        @elseif($msg->body_plain)
                                            <pre class="whitespace-pre-wrap font-sans text-[13px]">{{ $msg->body_plain }}</pre>
                                        @else
                                            <div class="text-fg-3">(пустое тело)</div>
                                        @endif

                                        @php
                                            $canReplyHere = auth()->id() === $req->assigned_user_id;
                                            $isDraftMsg = (bool) $msg->is_draft;
                                            $isMyDraft = $isDraftMsg && $msg->draft_author_user_id === auth()->id();
                                        @endphp
                                        @if($isDraftMsg)
                                            <div class="mt-2 flex items-center gap-2 text-[11.5px] text-amber-700">
                                                <span class="chip chip-attn"><span class="dot"></span>Черновик</span>
                                                @if($isMyDraft)
                                                    <button type="button"
                                                            wire:click="$dispatch('open-draft', { draftId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                                            class="underline">Продолжить редактирование</button>
                                                @endif
                                            </div>
                                        @elseif($canReplyHere && ! $isOutbound)
                                            <div class="mt-2 flex gap-2">
                                                <button type="button"
                                                        wire:click="$dispatch('open-reply', { messageId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                                        class="btn btn-sm">↩ Ответить</button>
                                                <button type="button"
                                                        wire:click="$dispatch('open-reply-all', { messageId: {{ $msg->id }}, requestId: {{ $req->id }} })"
                                                        class="btn btn-sm">↩↩ Ответить всем</button>
                                            </div>
                                        @endif

                                        @if($msg->attachments->isNotEmpty())
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                @foreach($msg->attachments as $att)
                                                    @php
                                                        $isImg = $isImageAttachment($att);
                                                        $previewUrl = route('attachments.preview', $att);
                                                        $downloadUrl = route('attachments.download', $att);
                                                    @endphp
                                                    @if($isImg)
                                                        {{-- Image thumbnail → клик открывает лайтбокс. --}}
                                                        <button type="button"
                                                                x-on:click="$dispatch('open-image', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                                class="block border border-border rounded-md overflow-hidden bg-surface hover:border-border-strong transition-colors text-left"
                                                                title="{{ $att->filename }}">
                                                            <img src="{{ $previewUrl }}"
                                                                 alt="{{ $att->filename }}"
                                                                 loading="lazy"
                                                                 class="w-[140px] h-[100px] object-cover block bg-app">
                                                            <div class="px-2 py-1 max-w-[140px] text-[11px] text-fg-3">
                                                                <span class="block truncate text-fg-1">{{ $att->filename }}</span>
                                                                @if($att->size_bytes)
                                                                    <span>{{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                                @endif
                                                            </div>
                                                        </button>
                                                    @else
                                                        <a href="{{ $downloadUrl }}"
                                                           class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover">
                                                            <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                                                {{ Str::upper(Str::afterLast($att->filename, '.')) ?: 'BIN' }}
                                                            </span>
                                                            <span class="truncate max-w-[280px]">{{ $att->filename }}</span>
                                                            @if($att->size_bytes)
                                                                <span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                            @endif
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            {{-- Phase 1.9 — Compose / Reply form. --}}
                            @if(auth()->id() === $req->assigned_user_id)
                                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[12px] text-fg-3">Ответ клиенту через MyLift — копия сохранится в Sent ящика.</span>
                                        <span class="flex-1"></span>
                                        @if($req->client_email)
                                            <button type="button"
                                                    wire:click="$dispatch('open-compose', { requestId: {{ $req->id }} })"
                                                    class="btn btn-sm">＋ Новое сообщение клиенту</button>
                                        @endif
                                    </div>
                                    <livewire:requests.mail.compose-form
                                        :request-id="$req->id"
                                        wire:key="compose-{{ $req->id }}" />
                                </div>
                            @else
                                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border text-[12px] text-fg-3">
                                    Отвечать на эту заявку может только назначенный менеджер
                                    ({{ $req->assignedUser?->name ?? '— не назначен —' }}).
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
                @break

            {{-- ───── ПОЗИЦИИ ───── --}}
            @case('items')
                @php
                    // Phase 2: очередь LLM-предположений об уточнениях артикулов.
                    // Заполняется decideClarifications при парсинге reply'я.
                    $pendingClarifications = is_array($req->pending_clarifications) ? $req->pending_clarifications : [];

                    // Foundation §6.2: история clarification-batch'ей этой заявки.
                    // Foundation §6.2: показываем ВСЕ batches заявки (drafted /
                    // sent / answered). Cancelled — нет (оператор откатил).
                    // Lazy-load если relation не eager (после dehydrate-цикла).
                    $clarificationBatches = $req->clarificationBatches
                        ->whereIn('status', ['drafted', 'sent', 'answered']);
                @endphp

                {{-- Старый верхний блок «История уточнений» удалён —
                     сводный блок теперь рендерится один раз под списком
                     позиций (см. ниже после @foreach($items)). --}}

                @if(! empty($pendingClarifications))
                    <div class="ds-card mb-3 border-amber-300">
                        <div class="ds-card-header bg-amber-50">
                            <h3 class="text-amber-900">Предложенные уточнения</h3>
                            <span class="text-[10.5px] font-semibold text-amber-900 bg-amber-100 px-1.5 py-0.5 rounded-full">{{ count($pendingClarifications) }}</span>
                            <span class="flex-1"></span>
                            <span class="text-[11.5px] text-amber-800">LLM нашёл в reply'е уточнения к существующим позициям — проверьте и примите или отклоните</span>
                        </div>
                        <div class="divide-y divide-amber-200">
                            @foreach($pendingClarifications as $clr)
                                @php
                                    $clrId = $clr['id'] ?? null;
                                    $targetPos = $clr['target_position'] ?? null;
                                    $targetItem = $targetPos ? $items->firstWhere('position', (int) $targetPos) : null;
                                    $addArticle = $clr['additional_article'] ?? null;
                                    $addBrand = $clr['additional_brand'] ?? null;
                                    $reasoning = $clr['reasoning'] ?? '';
                                @endphp
                                <div class="px-[18px] py-3 text-[12.5px]" wire:key="clr-{{ $clrId }}">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-fg-3 text-[11px]">К позиции</span>
                                                @if($targetItem)
                                                    <span class="mono text-[11px] text-fg-1">#{{ $targetItem->position }}</span>
                                                    <span class="font-medium text-fg-1">{{ $targetItem->parsed_name }}</span>
                                                    @if($targetItem->parsed_article)
                                                        <span class="mono text-fg-2 text-[11.5px]">{{ $targetItem->parsed_article }}</span>
                                                    @endif
                                                @else
                                                    <span class="text-fg-3">#{{ $targetPos }} (позиция не найдена)</span>
                                                @endif
                                            </div>
                                            <div class="mt-1.5 flex items-center gap-2 flex-wrap">
                                                <span class="text-fg-3 text-[11px]">Добавить:</span>
                                                @if($addArticle)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm bg-amber-100 text-amber-900 font-semibold text-[11.5px] mono">{{ $addArticle }}</span>
                                                @endif
                                                @if($addBrand)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm bg-emerald-100 text-emerald-900 font-medium text-[11.5px]">{{ $addBrand }}</span>
                                                @endif
                                                @if(! $addArticle && ! $addBrand)
                                                    <span class="text-fg-3 italic">пусто (можно только отклонить)</span>
                                                @endif
                                            </div>
                                            @if($reasoning !== '')
                                                <div class="text-[11px] text-fg-3 mt-1.5 italic">{{ $reasoning }}</div>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <button type="button"
                                                    wire:click="applyClarification({{ Js::from($clrId) }})"
                                                    wire:loading.attr="disabled"
                                                    @disabled(! $targetItem || (! $addArticle && ! $addBrand))
                                                    class="btn btn-sm bg-emerald-600 text-white border-emerald-700 hover:bg-emerald-700 disabled:opacity-50">
                                                Применить
                                            </button>
                                            <button type="button"
                                                    wire:click="rejectClarification({{ Js::from($clrId) }})"
                                                    wire:loading.attr="disabled"
                                                    class="btn btn-sm">
                                                Отклонить
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @php
                    // Priority 1: для toggle «Показать удалённые» нужно знать,
                    // есть ли вообще soft-deleted позиции — иначе кнопку прячем.
                    $deletedCount = \App\Models\RequestItem::query()
                        ->where('request_id', $req->id)
                        ->where('is_active', false)
                        ->count();
                    $canEditItems = auth()->id() === $req->assigned_user_id
                        || auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary']);
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Позиции запроса</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $items->count() }}</span>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3">источник: {{ $items->first()?->data_source ?? '—' }}</span>
                        @if($deletedCount > 0)
                            <button wire:click="toggleDeletedItems" class="btn btn-sm">
                                @if($showDeletedItems)
                                    Скрыть удалённые
                                @else
                                    Показать удалённые ({{ $deletedCount }})
                                @endif
                            </button>
                        @endif
                        @php
                            // Sticky-positions toggle — отображаем только если у заявки реально есть связанные.
                            $relatedSticky = $this->relatedStickyRequests;
                            $stickyItemsCount = $relatedSticky->sum(fn ($r) => $r->items->count());
                        @endphp
                        @if($relatedSticky->isNotEmpty())
                            <button wire:click="toggleStickyItems" class="btn btn-sm"
                                    title="Показать позиции связанных через sticky заявок ({{ $relatedSticky->count() }} шт · {{ $stickyItemsCount }} позиций)">
                                @if($includeStickyItems)
                                    Скрыть sticky-позиции
                                @else
                                    📎 Sticky-позиции ({{ $stickyItemsCount }})
                                @endif
                            </button>
                        @endif
                        @if($canEditItems && $items->isNotEmpty())
                            <button type="button"
                                    wire:click="rematchAllItems"
                                    wire:confirm="Сбросить текущие привязки к каталогу для всех позиций (кроме «нет в каталоге») и заново их сматчить через A/B/C? Это займёт несколько секунд."
                                    wire:loading.attr="disabled"
                                    wire:target="rematchAllItems"
                                    class="btn btn-sm"
                                    title="Сбросить привязки и заново сматчить через A/B/C">
                                <span wire:loading.remove wire:target="rematchAllItems">🔄 Refresh всех</span>
                                <span wire:loading wire:target="rematchAllItems">⏳ Пересматчиваем…</span>
                            </button>
                        @else
                            <button class="btn btn-sm" disabled>Refresh всех</button>
                        @endif
                    </div>

                    @if($items->isEmpty())
                        <div class="ds-card-body text-center text-fg-3 py-8">
                            Парсер позиций ещё не отработал.
                            <div class="text-[12px] mt-2 text-fg-4">
                                Задача в очереди — обновите страницу через минуту, либо РОП может перезапустить парсер.
                            </div>
                        </div>
                    @else
                        <div>
                            @php
                                // Foundation §6.2 + дизайн 04b: slot-based view.
                                $slotResolver = app(\App\Services\Kb\PositionSlotResolver::class);
                                $aggregate = $slotResolver->aggregateProgress($items);

                                $itemsWithPending = $items->filter(fn ($i) => $i->is_active
                                    && $i->clarificationQuestions->isNotEmpty()
                                    && $i->clarificationQuestions->contains(fn ($q) => trim((string) $q->answer) === ''
                                        && in_array($q->batch?->status, ['sent', 'answered'], true)))->count();
                                $itemsWithJustAnswered = $items->filter(fn ($i) => $i->is_active
                                    && $i->clarificationQuestions->isNotEmpty()
                                    && $i->clarificationQuestions->contains(fn ($q) => trim((string) $q->answer) !== ''))->count();
                                $itemsWithEnrichment = $items->filter(function ($i) {
                                    $sugg = is_array($i->quality_assessment_payload['enrichment_suggestions'] ?? null)
                                        ? $i->quality_assessment_payload['enrichment_suggestions'] : [];
                                    return $i->is_active && ! empty(array_filter($sugg,
                                        fn ($s) => is_array($s) && ($s['status'] ?? 'pending') === 'pending'));
                                })->count();
                            @endphp

                            {{-- Hero strip: aggregate slot progress + notice chips --}}
                            <div class="px-[18px] py-2.5 border-b border-border-subtle bg-surface-2 flex items-center gap-3 text-[12.5px] flex-wrap">
                                <span class="text-fg-3 text-[11px] uppercase tracking-wider font-semibold">Заполнено</span>
                                <span class="text-fg-1 font-semibold mono">{{ $aggregate['filled'] }}/{{ $aggregate['total'] }}</span>
                                <div class="flex-1 max-w-[260px] h-1.5 bg-border-subtle rounded-full overflow-hidden">
                                    <div class="h-full bg-emerald-500 transition-all" style="width: {{ $aggregate['percent'] }}%"></div>
                                </div>
                                <span class="mono text-fg-2 text-[11.5px]">{{ $aggregate['percent'] }}%</span>
                                <span class="flex-1"></span>
                                @if($itemsWithEnrichment > 0)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm bg-amber-50 text-amber-800 text-[10.5px] font-semibold"
                                          title="есть предложения для одного клика «применить»">
                                        💡 предложения · {{ $itemsWithEnrichment }}
                                    </span>
                                @endif
                                @if($itemsWithPending > 0)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm bg-amber-50 text-amber-800 text-[10.5px] font-semibold"
                                          title="отправленные вопросы без ответа">
                                        ⏳ ждут ответа · {{ $itemsWithPending }}
                                    </span>
                                @endif
                                @if($itemsWithJustAnswered > 0)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm bg-emerald-50 text-emerald-800 text-[10.5px] font-semibold"
                                          title="позиции с полученным ответом от клиента">
                                        ✓ уточнено · {{ $itemsWithJustAnswered }}
                                    </span>
                                @endif
                            </div>

                            {{-- Foundation §6.2 Phase E — топ-секция «Предложенные
                                 уточнения»: все pending suggestions со всех позиций,
                                 diff-визуал «было → будет», bar уверенности LLM,
                                 bulk apply/dismiss. --}}
                            @php
                                $allPendingSuggestions = collect();
                                foreach ($items as $_it) {
                                    $_sgs = is_array($_it->quality_assessment_payload['enrichment_suggestions'] ?? null)
                                        ? $_it->quality_assessment_payload['enrichment_suggestions'] : [];
                                    foreach ($_sgs as $_sg) {
                                        if (is_array($_sg) && ($_sg['status'] ?? 'pending') === 'pending') {
                                            $allPendingSuggestions->push(['item' => $_it, 'sugg' => $_sg]);
                                        }
                                    }
                                }
                            @endphp
                            @if($allPendingSuggestions->isNotEmpty() && $canEditItems)
                                <div class="mt-3 ds-card border-amber-300">
                                    <div class="ds-card-header bg-amber-50">
                                        <span class="text-[14px]">💡</span>
                                        <h3 class="m-0 text-amber-900">Предложенные уточнения</h3>
                                        <span class="text-[10.5px] font-semibold text-amber-900 bg-amber-200 px-1.5 py-0.5 rounded-full">{{ $allPendingSuggestions->count() }}</span>
                                        <span class="text-[11.5px] text-amber-800 ml-1">· LLM нашёл уточнения к существующим позициям</span>
                                        <span class="flex-1"></span>
                                        <button type="button"
                                                wire:click="dismissAllEnrichments"
                                                wire:confirm="Отклонить все {{ $allPendingSuggestions->count() }} предложений?"
                                                class="btn btn-sm">Отклонить все</button>
                                        <button type="button"
                                                wire:click="applyAllEnrichments"
                                                wire:confirm="Применить все {{ $allPendingSuggestions->count() }} предложений? Данные запишутся в позиции."
                                                class="btn btn-sm btn-primary">✓ Применить все ({{ $allPendingSuggestions->count() }})</button>
                                    </div>
                                    <div class="divide-y divide-border-subtle">
                                        @foreach($allPendingSuggestions as $entry)
                                            @php
                                                $_it = $entry['item'];
                                                $_sg = $entry['sugg'];
                                                $_sid = (string) ($_sg['id'] ?? '');
                                                $_field = (string) ($_sg['field'] ?? '');
                                                $_newVal = (string) ($_sg['value'] ?? '');
                                                $_quote = (string) ($_sg['source_quote'] ?? '');
                                                $_conf = (float) ($_sg['confidence'] ?? 0);
                                                $_confPct = (int) round($_conf * 100);

                                                // Slots для этой позиции — нужен label slot'а и текущее значение.
                                                $_slots = $slotResolver->resolve($_it);
                                                $_slotsByKey = collect($_slots)->keyBy('key');

                                                // Map field → label + current.
                                                $_currentValue = null;
                                                $_targetLabel = $_field;
                                                if ($_field === 'parsed_brand') {
                                                    $_currentValue = $_it->brand?->name ?: ($_it->parsed_brand ?: null);
                                                    $_targetLabel = 'Бренд';
                                                } elseif ($_field === 'parsed_article') {
                                                    $_currentValue = $_it->parsed_article ?: null;
                                                    $_targetLabel = 'Артикул';
                                                } elseif ($_field === 'parsed_qty') {
                                                    $_currentValue = $_it->parsed_qty
                                                        ? rtrim(rtrim((string) $_it->parsed_qty, '0'), '.') . ' ' . ($_it->parsed_unit ?: 'шт.')
                                                        : null;
                                                    $_targetLabel = 'Кол-во';
                                                } elseif (str_starts_with($_field, 'kb:')) {
                                                    $_slug = substr($_field, 3);
                                                    $_extracted = is_array($_it->quality_assessment_payload['extracted_parameters'] ?? null)
                                                        ? $_it->quality_assessment_payload['extracted_parameters'] : [];
                                                    $_currentValue = $_extracted[$_slug] ?? null;
                                                    $_targetLabel = $_slotsByKey[$_field]['label'] ?? $_slug;
                                                }

                                                // Confidence bar color.
                                                $_confColor = $_confPct >= 90 ? 'bg-emerald-500' : ($_confPct >= 75 ? 'bg-amber-500' : 'bg-red-500');
                                                $_confTextColor = $_confPct >= 90 ? 'text-emerald-700' : ($_confPct >= 75 ? 'text-amber-700' : 'text-red-700');
                                            @endphp
                                            <div class="px-[18px] py-3 grid gap-4"
                                                 style="grid-template-columns: minmax(0, 1fr) 220px"
                                                 wire:key="sugg-{{ $_it->id }}-{{ $_sid }}">
                                                {{-- LEFT: position + diff + quote --}}
                                                <div class="min-w-0">
                                                    <div class="flex items-baseline gap-2 flex-wrap text-[12px] mb-2">
                                                        <span class="text-fg-3 text-[10.5px] uppercase tracking-wider font-semibold">К позиции</span>
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm bg-surface-2 border border-border-subtle text-fg-2 mono text-[11px]">#{{ $_it->position }}</span>
                                                        <span class="font-medium text-fg-1">{{ $_it->parsed_name ?: '(без названия)' }}</span>
                                                        @if($_it->brand)
                                                            <span class="inline-flex items-center px-1.5 rounded-sm bg-neutral-100 text-neutral-700 font-semibold text-[10.5px] uppercase tracking-wider">{{ $_it->brand->name }}</span>
                                                        @elseif($_it->parsed_brand)
                                                            <span class="inline-flex items-center px-1.5 rounded-sm bg-neutral-100 text-neutral-700 font-semibold text-[10.5px] uppercase tracking-wider">{{ $_it->parsed_brand }}</span>
                                                        @endif
                                                        @if($_it->parsed_qty)
                                                            <span class="text-fg-3 text-[11px]">· {{ rtrim(rtrim((string) $_it->parsed_qty, '0'), '.') }} {{ $_it->parsed_unit ?: 'шт.' }}</span>
                                                        @endif
                                                    </div>

                                                    {{-- DIFF: было → будет --}}
                                                    <div class="flex items-center gap-2 flex-wrap text-[12.5px] mb-2">
                                                        <span class="text-fg-3 text-[10.5px] uppercase tracking-wider font-semibold mr-1">{{ $_currentValue !== null ? 'Изменение' : 'Заполнение' }}</span>
                                                        @if($_currentValue !== null)
                                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm bg-neutral-100 text-fg-2 text-[11.5px]">
                                                                <span class="text-[10px] uppercase tracking-wider text-fg-3">{{ $_targetLabel }}</span>
                                                                <span class="mono line-through decoration-red-400">{{ $_currentValue }}</span>
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm bg-neutral-100 text-fg-3 text-[11.5px]">
                                                                <span class="text-[10px] uppercase tracking-wider">{{ $_targetLabel }}</span>
                                                                <span class="italic">пусто</span>
                                                            </span>
                                                        @endif
                                                        <span class="text-fg-3 text-[14px] leading-none">→</span>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm bg-emerald-50 border border-emerald-300 text-emerald-900 text-[11.5px] font-medium">
                                                            <span class="text-[10px] uppercase tracking-wider text-emerald-700">{{ $_targetLabel }}</span>
                                                            <span class="mono font-semibold">{{ $_newVal }}</span>
                                                        </span>
                                                    </div>

                                                    {{-- Quote --}}
                                                    @if($_quote !== '')
                                                        <div class="text-[12px] text-fg-2 italic pl-3 border-l-2 border-amber-300">
                                                            «{{ \Illuminate\Support\Str::limit($_quote, 240) }}»
                                                        </div>
                                                    @endif
                                                </div>

                                                {{-- RIGHT: confidence bar + actions --}}
                                                <div class="flex flex-col gap-2">
                                                    <div>
                                                        <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Уверенность LLM</div>
                                                        <div class="h-1.5 bg-border-subtle rounded-full overflow-hidden">
                                                            <div class="h-full {{ $_confColor }} transition-all" style="width: {{ $_confPct }}%"></div>
                                                        </div>
                                                        <div class="mt-1 mono text-[12.5px] font-semibold {{ $_confTextColor }}">{{ $_confPct }}%</div>
                                                    </div>
                                                    <div class="flex flex-col gap-1">
                                                        <button type="button"
                                                                wire:click="applyEnrichmentSuggestion({{ $_it->id }}, '{{ $_sid }}')"
                                                                wire:confirm="Применить «{{ $_newVal }}» к полю «{{ $_targetLabel }}»?"
                                                                class="btn btn-primary btn-sm w-full">✓ Применить</button>
                                                        <button type="button"
                                                                wire:click="dismissEnrichmentSuggestion({{ $_it->id }}, '{{ $_sid }}')"
                                                                class="btn btn-sm w-full">Отклонить</button>
                                                        <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                                            <button type="button" @click="open = !open"
                                                                    class="text-[11px] text-sky-700 hover:underline w-full text-center">правка вручную ▾</button>
                                                            <div x-show="open" x-cloak x-transition
                                                                 class="absolute right-0 top-full mt-1 z-30 w-[220px] py-1 bg-surface border border-border rounded-md shadow-lg text-left text-[12px]">
                                                                <div class="px-3 py-1 text-fg-3 text-[10.5px] uppercase tracking-wider font-semibold border-b border-border-subtle">
                                                                    Применить в другой слот:
                                                                </div>
                                                                @foreach($_slots as $_sl)
                                                                    @php
                                                                        $_disabled = $_sl['status'] === 'filled';
                                                                    @endphp
                                                                    <button type="button"
                                                                            @click="open = false"
                                                                            @if(! $_disabled)
                                                                                wire:click="applyEnrichmentToSlot({{ $_it->id }}, '{{ $_sid }}', '{{ $_sl['key'] }}')"
                                                                                wire:confirm="Записать «{{ $_newVal }}» в «{{ $_sl['label'] }}»?"
                                                                            @endif
                                                                            @disabled($_disabled)
                                                                            class="block w-full text-left px-3 py-1.5 {{ $_disabled ? 'text-fg-4 cursor-not-allowed' : 'hover:bg-sky-50 text-fg-1' }}">
                                                                        <span>{{ $_sl['label'] }}</span>
                                                                        @if($_disabled)
                                                                            <span class="text-fg-3 text-[10.5px]">· заполнен</span>
                                                                        @endif
                                                                    </button>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Foundation §6.2 Phase E.3 — applied lane:
                                 показываем applied / auto_applied suggestions
                                 за последние ~20 штук, с кнопкой «откатить».
                                 Помогает менеджеру быстро отозвать ошибочно
                                 применённое значение, не лезя в правки. --}}
                            @php
                                $allAppliedSuggestions = collect();
                                foreach ($items as $_it) {
                                    $_sgs = is_array($_it->quality_assessment_payload['enrichment_suggestions'] ?? null)
                                        ? $_it->quality_assessment_payload['enrichment_suggestions'] : [];
                                    foreach ($_sgs as $_sg) {
                                        if (is_array($_sg) && ($_sg['status'] ?? '') === 'applied') {
                                            $allAppliedSuggestions->push(['item' => $_it, 'sugg' => $_sg]);
                                        }
                                    }
                                }
                                // newest first by applied_at
                                $allAppliedSuggestions = $allAppliedSuggestions
                                    ->sortByDesc(fn ($e) => $e['sugg']['applied_at'] ?? '')
                                    ->take(20);
                            @endphp
                            @if($allAppliedSuggestions->isNotEmpty() && $canEditItems)
                                <div class="mt-2 ds-card border-emerald-200">
                                    <div class="ds-card-header bg-emerald-50/50">
                                        <span class="text-[14px]">✓</span>
                                        <h3 class="m-0 text-emerald-900 text-[13px]">Применено</h3>
                                        <span class="text-[10.5px] font-semibold text-emerald-800 bg-emerald-100 px-1.5 py-0.5 rounded-full">{{ $allAppliedSuggestions->count() }}</span>
                                        <span class="text-[11px] text-emerald-700 ml-1">· можно откатить если применили зря</span>
                                    </div>
                                    <div class="divide-y divide-border-subtle">
                                        @foreach($allAppliedSuggestions as $entry)
                                            @php
                                                $_it = $entry['item'];
                                                $_sg = $entry['sugg'];
                                                $_sid = (string) ($_sg['id'] ?? '');
                                                $_field = (string) ($_sg['applied_to_slot'] ?? $_sg['field'] ?? '');
                                                $_val = (string) ($_sg['value'] ?? '');
                                                $_autoApplied = (bool) ($_sg['auto_applied'] ?? false);
                                                $_appliedAt = $_sg['applied_at'] ?? null;

                                                // Slot label resolution
                                                $_slots = $slotResolver->resolve($_it);
                                                $_slotsByKey = collect($_slots)->keyBy('key');
                                                $_label = match (true) {
                                                    $_field === 'brand', $_field === 'parsed_brand'     => 'Бренд',
                                                    $_field === 'article', $_field === 'parsed_article' => 'Артикул',
                                                    $_field === 'qty', $_field === 'parsed_qty'         => 'Кол-во',
                                                    str_starts_with($_field, 'kb:') => $_slotsByKey[$_field]['label'] ?? substr($_field, 3),
                                                    default => $_field,
                                                };
                                            @endphp
                                            <div class="px-[18px] py-1.5 flex items-center gap-2.5 text-[12px]"
                                                 wire:key="appliedsugg-{{ $_it->id }}-{{ $_sid }}">
                                                @if($_autoApplied)
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm bg-violet-100 text-violet-800 text-[10px] font-semibold uppercase tracking-wider shrink-0">AI авто</span>
                                                @else
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm bg-emerald-100 text-emerald-800 text-[10px] font-semibold uppercase tracking-wider shrink-0">вручную</span>
                                                @endif
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm bg-surface-2 border border-border-subtle text-fg-2 text-[10.5px] mono shrink-0">#{{ $_it->position }}</span>
                                                <span class="text-fg-2 truncate">{{ \Illuminate\Support\Str::limit($_it->parsed_name ?: '(без названия)', 45) }}</span>
                                                <span class="text-fg-3 text-[10.5px] shrink-0">{{ $_label }}</span>
                                                <span class="text-fg-3 text-[14px] leading-none shrink-0">→</span>
                                                <span class="mono text-fg-1 font-medium truncate">{{ $_val }}</span>
                                                <span class="flex-1"></span>
                                                @if($_appliedAt)
                                                    <span class="text-fg-3 text-[10.5px] mono shrink-0">{{ \Carbon\Carbon::parse($_appliedAt)->format('d.m H:i') }}</span>
                                                @endif
                                                <button type="button"
                                                        wire:click="rollbackEnrichmentSuggestion({{ $_it->id }}, '{{ $_sid }}')"
                                                        wire:confirm="Откатить применение «{{ $_val }}» в слот «{{ $_label }}»?"
                                                        class="text-[11px] text-red-600 hover:underline shrink-0">↶ откатить</button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="p-[8px] bg-app">
                            @foreach($items as $item)
                                @php $slots = $slotResolver->resolve($item); @endphp
                                @include('livewire.requests.items._position-card', [
                                    'item' => $item,
                                    'slots' => $slots,
                                    'isImageAttachment' => $isImageAttachment,
                                    'canEditItems' => $canEditItems,
                                    'items' => $items,
                                    'expanded' => (bool) ($expandedPositions[$item->id] ?? false),
                                ])
                            @endforeach
                            </div>

                            {{-- Foundation §6.2 — сводный блок «История уточнений»
                                 под списком позиций. Раньше дублировался в каждой
                                 раскрытой карточке. Теперь один раз: все вопросы/
                                 ответы по всем позициям, по datetime. Скрывается
                                 если уточнений никаких ещё не было. --}}
                            @php
                                $allClarQuestions = $items
                                    ->flatMap(fn ($i) => $i->clarificationQuestions)
                                    ->filter(fn ($q) => $q->batch !== null)
                                    ->sortBy(fn ($q) => $q->batch?->sent_at?->timestamp ?? $q->id);
                            @endphp
                            @if($allClarQuestions->isNotEmpty())
                                <div class="mt-4 ds-card">
                                    <div class="ds-card-header">
                                        <span class="text-[14px]">📜</span>
                                        <h3 class="m-0">История уточнений</h3>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full bg-surface-2 text-fg-2 text-[10.5px] font-semibold">
                                            {{ $allClarQuestions->count() }}
                                        </span>
                                    </div>
                                    <div class="ds-card-body">
                                        <div class="space-y-2">
                                            @foreach($allClarQuestions as $cq)
                                                @php
                                                    $cqBatch = $cq->batch;
                                                    $isSent = $cqBatch && in_array($cqBatch->status, ['sent', 'answered'], true);
                                                    $answerText = trim((string) $cq->answer);
                                                    // Defensive: legacy "null"/"—" из старых LLM-ответов трактуем как «нет ответа».
                                                    if (in_array(mb_strtolower($answerText), ['null', 'none', '—', '-', 'n/a'], true)) {
                                                        $answerText = '';
                                                    }
                                                    $hasAnswer = $answerText !== '';
                                                    $stateColor = $hasAnswer
                                                        ? 'bg-emerald-500'
                                                        : ($isSent ? 'bg-amber-500' : 'bg-neutral-400');
                                                    $cqItem = $cq->requestItem;
                                                @endphp
                                                <div class="grid items-start gap-3 px-2 py-2 rounded-md hover:bg-surface-2 text-[12.5px]"
                                                     style="grid-template-columns: 10px 60px 1fr 100px">
                                                    {{-- State dot --}}
                                                    <span class="w-2.5 h-2.5 rounded-full {{ $stateColor }} mt-1.5"></span>

                                                    {{-- Position pill --}}
                                                    @if($cqItem)
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm bg-surface-2 border border-border-subtle text-fg-2 text-[11px] font-medium mono leading-tight">
                                                            #{{ $cqItem->position }}
                                                        </span>
                                                    @else
                                                        <span class="text-fg-3 text-[10.5px] uppercase tracking-wider">общий</span>
                                                    @endif

                                                    {{-- Question + Answer --}}
                                                    <div class="min-w-0">
                                                        @if($cqItem)
                                                            <div class="text-[11.5px] text-fg-3 mb-0.5 leading-tight">
                                                                {{ $cqItem->parsed_name ?: '(без названия)' }}
                                                            </div>
                                                        @endif
                                                        <div class="text-fg-1 leading-snug">
                                                            <b class="font-medium">{{ $cqBatch?->createdBy?->name ?? 'Менеджер' }}</b>
                                                            спросил:
                                                            <span class="text-fg-2">{{ $cq->question }}</span>
                                                        </div>
                                                        @if($hasAnswer)
                                                            <div class="mt-1.5 px-2.5 py-1.5 rounded-sm bg-emerald-50 border-l-2 border-emerald-400">
                                                                <span class="text-emerald-700 font-semibold text-[10px] uppercase tracking-wider">Клиент{{ $cq->answered_at ? ' · ' . $cq->answered_at->format('d.m H:i') : '' }}:</span>
                                                                <div class="text-fg-1 mt-0.5">{{ $answerText }}</div>
                                                            </div>
                                                        @endif
                                                    </div>

                                                    {{-- Timestamp + state --}}
                                                    <div class="text-right text-fg-3 mono text-[10.5px] leading-tight whitespace-nowrap">
                                                        {{ $cqBatch?->sent_at?->format('d.m H:i') ?: '—' }}
                                                        <div class="mt-0.5">
                                                            {{ $hasAnswer ? '✓ отвечено' : ($isSent ? '⏳ ждём' : '✏ черновик') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif

                            @php
                                // Phase 2 use-case C: подытог по каталожным ценам тех позиций,
                                // у которых есть привязка и непустое qty. Если у части позиций
                                // привязки нет — они в подсчёт не идут, в подсказке покажем
                                // сколько посчитано.
                                $subtotal = 0.0;
                                $countedItems = 0;
                                foreach ($items as $itm) {
                                    $p = $itm->catalogItem?->price;
                                    $q = (float) ($itm->parsed_qty ?? 0);
                                    if ($p !== null && $q > 0) {
                                        $subtotal += (float) $p * $q;
                                        $countedItems++;
                                    }
                                }
                                $vatPercent = (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22));
                                $vat = $subtotal * $vatPercent / 100;
                                $totalAll = $subtotal + $vat;
                                $hasAnyPrice = $countedItems > 0;
                                $totalsTitle = $hasAnyPrice
                                    ? "посчитано позиций: {$countedItems} из {$items->count()}"
                                    : 'ни одна позиция не привязана к каталогу';
                            @endphp
                            <div class="px-[18px] py-3 bg-surface-2 flex items-center gap-3.5 text-[12.5px] border-t border-border-subtle rounded-b-md"
                                 title="{{ $totalsTitle }}">
                                <span class="text-fg-3" title="Phase 2">+ добавить позицию</span>
                                <span class="flex-1"></span>
                                <span class="text-fg-3">подытог:</span><span class="{{ $hasAnyPrice ? 'text-fg-1' : 'text-fg-3' }} mono">{{ $hasAnyPrice ? number_format($subtotal, 2, '.', ' ') . ' ₽' : '—' }}</span>
                                <span class="text-fg-3">+ НДС {{ rtrim(rtrim(number_format($vatPercent, 1, '.', ''), '0'), '.') }}%:</span><span class="{{ $hasAnyPrice ? 'text-fg-1' : 'text-fg-3' }} mono">{{ $hasAnyPrice ? number_format($vat, 2, '.', ' ') . ' ₽' : '—' }}</span>
                                <span class="text-fg-3">итого:</span><span class="{{ $hasAnyPrice ? 'text-fg-1' : 'text-fg-3' }} mono text-[14px]">{{ $hasAnyPrice ? number_format($totalAll, 2, '.', ' ') . ' ₽' : '—' }}</span>
                            </div>
                        </div>
                    @endif

                    {{-- Phase 1.10 — позиции sticky-связанных заявок (toggle в шапке).
                         Тот же layout что и у основного списка (через partial _item-row),
                         только в readonly-режиме — actions заменены на ↗ (открыть заявку).--}}
                    @if($includeStickyItems && $relatedSticky->isNotEmpty())
                        <div class="mt-4 ds-card">
                            <div class="ds-card-header">
                                <h3>Позиции sticky-связанных заявок</h3>
                                <span class="text-[10.5px] font-semibold text-fg-2 bg-violet-50 text-violet-800 px-1.5 py-0.5 rounded-full">{{ $relatedSticky->count() }} заявок · {{ $stickyItemsCount }} позиций</span>
                                <span class="flex-1"></span>
                                <span class="text-[11.5px] text-fg-3">Контекст: что клиент уже спрашивал в связанных заявках</span>
                            </div>
                            <div>
                                {{-- Header-row как у основной таблицы. --}}
                                <div class="grid items-center px-[18px] gap-2.5 text-[11px] uppercase tracking-wider text-fg-3 font-semibold border-b border-border-subtle"
                                     style="grid-template-columns: 24px 36px 1fr 110px 90px 100px 110px 56px; height: 30px">
                                    <span></span><span></span>
                                    <span>позиция</span>
                                    <span>кол-во</span>
                                    <span>цена</span>
                                    <span>наличие</span>
                                    <span class="text-right">сумма</span>
                                    <span></span>
                                </div>
                                @foreach($relatedSticky as $linkedReq)
                                    {{-- Group-header: код связанной заявки + статус + менеджер + дата. --}}
                                    <div class="px-[18px] py-2 border-b border-border-subtle bg-surface-2 flex items-center gap-2 text-[12px]">
                                        <a href="{{ route('requests.show', $linkedReq) }}"
                                           class="mono text-[13px] text-sky-700 hover:underline font-semibold">{{ $linkedReq->internal_code }}</a>
                                        <span class="chip {{ $linkedReq->status->chipClass() }} text-[10.5px]">
                                            <span class="dot"></span>{{ $linkedReq->status->label() }}
                                        </span>
                                        @if($linkedReq->assignedUser)
                                            <span class="text-[11.5px] text-fg-3">· {{ $linkedReq->assignedUser->name }}</span>
                                        @endif
                                        <span class="text-[11.5px] text-fg-3">· {{ $linkedReq->created_at?->format('d.m.Y') }}</span>
                                        <span class="flex-1"></span>
                                        <span class="text-[11.5px] text-fg-3">{{ $linkedReq->items->count() }} позиций</span>
                                    </div>
                                    @foreach($linkedReq->items as $linkedItem)
                                        @include('livewire.requests.items._item-row', [
                                            'item' => $linkedItem,
                                            'isImageAttachment' => $isImageAttachment,
                                            'readonly' => true,
                                            'canEditItems' => false,
                                            'items' => collect(),
                                        ])
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Priority 1: модалки ручных действий с позициями.
                         Single-instance, слушают $dispatch события от строк. --}}
                    @if($canEditItems)
                        <livewire:requests.items.item-edit-dialog
                            :request-id="$req->id"
                            wire:key="item-edit-{{ $req->id }}" />
                        <livewire:requests.items.item-catalog-link-dialog
                            :request-id="$req->id"
                            wire:key="item-catalog-link-{{ $req->id }}" />
                        <livewire:requests.items.item-photo-rebind-dialog
                            :request-id="$req->id"
                            wire:key="item-photo-rebind-{{ $req->id }}" />
                    @endif

                    {{-- Foundation §6.2: панель уточняющих вопросов клиенту.
                         Доступна и read-only ролям (но они увидят disabled).
                         Кнопка «Сформировать письмо» откроет draft в табе
                         «Переписка» через open-draft event. --}}
                    <livewire:requests.items.clarification-panel
                        :request-id="$req->id"
                        wire:key="clarification-{{ $req->id }}" />
                </div>
                @break

            {{-- ───── ПОСТАВЩИКИ ───── --}}
            @case('suppliers')
                <div class="ds-card p-8 text-center text-fg-3">
                    <div class="text-fg-1 font-medium mb-1">Поставщики и refresh цен — Phase 2</div>
                    <div class="text-sm">Привязка к поставщикам, batch-refresh запросы, latency-мониторинг.</div>
                </div>
                @break

            {{-- ───── АКТИВНОСТЬ ───── --}}
            @case('activity')
                @php
                    // Phase 1.10: merge stateChanges + assignments в один timeline
                    // отсортированный по created_at DESC. Каждый элемент — массив
                    // {at, kind, title, by, details}.
                    $timeline = collect();

                    foreach ($req->stateChanges as $sc) {
                        $fromEnum = $sc->fromStatusEnum();
                        $toEnum = $sc->toStatusEnum();
                        $title = $fromEnum
                            ? sprintf('Статус: «%s» → «%s»', $fromEnum->label(), $toEnum?->label() ?? $sc->to_status)
                            : sprintf('Заявка создана со статусом «%s»', $toEnum?->label() ?? $sc->to_status);
                        $kind = match ($sc->event) {
                            'auto_resume_pause' => 'state-auto',
                            'reanimate' => 'state-reanimate',
                            default => 'state',
                        };
                        $by = $sc->byUser?->name ?? match ($sc->event) {
                            'auto_resume_pause' => 'cron · авто-возврат с паузы',
                            'reanimate' => 'InboundReplyLinker · автоматически',
                            default => '—',
                        };
                        $timeline->push([
                            'at' => $sc->created_at,
                            'kind' => $kind,
                            'title' => $title,
                            'by' => $by,
                            'details' => $sc->comment,
                        ]);
                    }

                    foreach ($assignments as $a) {
                        $timeline->push([
                            'at' => $a->assigned_at,
                            'kind' => 'assignment',
                            'title' => 'Назначен ' . ($a->user?->name ?? '—'),
                            'by' => $a->assignedBy?->name,
                            'details' => $a->reason,
                        ]);
                    }

                    if ($email) {
                        $timeline->push([
                            'at' => $email->sent_at,
                            'kind' => 'email',
                            'title' => 'Получено письмо от ' . ($email->from_name ?: $email->from_email),
                            'by' => null,
                            'details' => $email->attachments->count() > 0
                                ? $email->attachments->count() . ' вложений'
                                : null,
                        ]);
                    }

                    $timeline = $timeline
                        ->filter(fn ($e) => $e['at'] !== null)
                        ->sortByDesc(fn ($e) => $e['at']->timestamp)
                        ->values();
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Активность</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $timeline->count() }}</span>
                    </div>
                    <div class="ds-card-body">
                        <div class="relative pl-5 text-[12.5px]">
                            <div class="absolute left-[5px] top-1.5 bottom-1.5 w-px bg-border-strong"></div>
                            @foreach($timeline as $event)
                                @php
                                    $dotClass = match ($event['kind']) {
                                        'state' => 'bg-sky-700 border-sky-700',
                                        'state-auto' => 'bg-amber-600 border-amber-600',
                                        'state-reanimate' => 'bg-violet-600 border-violet-600',
                                        'assignment' => 'bg-violet-700 border-violet-700',
                                        'email' => 'bg-emerald-700 border-emerald-700',
                                        default => 'bg-surface border-neutral-400',
                                    };
                                    $iconText = match ($event['kind']) {
                                        'state' => '🔄',
                                        'state-auto' => '⏰',
                                        'state-reanimate' => '↻',
                                        'assignment' => '👤',
                                        'email' => '✉',
                                        default => '·',
                                    };
                                @endphp
                                <div class="relative py-1.5">
                                    <span class="absolute -left-[15px] top-2.5 w-2.5 h-2.5 rounded-full {{ $dotClass }} border-[1.5px]"></span>
                                    <div class="text-fg-1 leading-snug">
                                        <span class="mr-1">{{ $iconText }}</span>{{ $event['title'] }}
                                    </div>
                                    @if($event['details'])
                                        <div class="text-fg-2 text-[11.5px] mt-0.5 whitespace-pre-wrap">{{ $event['details'] }}</div>
                                    @endif
                                    <div class="mono text-[11px] text-fg-3 mt-0.5">
                                        {{ $event['at']->format('d.m.Y H:i') }}
                                        @if($event['by'])
                                            · {{ $event['by'] }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @break

            {{-- ───── ФАЙЛЫ ───── --}}
            @case('files')
                @php
                    // Все вложения треда: trigger-email + reply'и.
                    $allAttachments = $thread->flatMap(fn ($m) => $m->attachments)->values();
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Файлы</h3>
                        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $allAttachments->count() }}</span>
                    </div>
                    @if($allAttachments->isEmpty())
                        <div class="ds-card-body text-fg-3 text-sm">Нет вложений.</div>
                    @else
                        <div class="divide-y divide-[var(--border-subtle)]">
                            @foreach($allAttachments as $att)
                                @php
                                    $ext = Str::upper(Str::afterLast($att->filename, '.')) ?: 'BIN';
                                    $isImg = $isImageAttachment($att);
                                    $previewUrl = route('attachments.preview', $att);
                                    $downloadUrl = route('attachments.download', $att);
                                @endphp
                                <div class="flex items-center gap-3 px-[18px] py-2.5 hover:bg-hover transition-colors">
                                    @if($isImg)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                class="shrink-0 block border border-border rounded-sm overflow-hidden bg-app"
                                                title="{{ $att->filename }}">
                                            <img src="{{ $previewUrl }}"
                                                 alt="{{ $att->filename }}"
                                                 loading="lazy"
                                                 class="w-12 h-12 object-cover block">
                                        </button>
                                    @else
                                        <span class="inline-block w-7 h-9 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[8.5px] font-bold text-center leading-9 shrink-0">{{ $ext }}</span>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="text-fg-1 truncate text-sm">{{ $att->filename }}</div>
                                        <div class="text-[11.5px] text-fg-3 mt-0.5">
                                            {{ $att->mime_type ?: '—' }}
                                            @if($att->size_bytes) · {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB @endif
                                            @if($att->is_inline) · <span class="text-sky-700">inline</span> @endif
                                        </div>
                                    </div>
                                    @if($isImg)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                class="text-sky-700 text-xs hover:underline">просмотр →</button>
                                    @endif
                                    <a href="{{ $downloadUrl }}" class="text-sky-700 text-xs hover:underline">скачать →</a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                @break

            {{-- ───── СВЯЗАННЫЕ ───── --}}
            @case('related')
                <div class="ds-card p-8 text-center text-fg-3">
                    <div class="text-fg-1 font-medium mb-1">Связанные заявки и sticky-история — Phase 2</div>
                    <div class="text-sm">Список заявок того же клиента + цепочка sticky-роутинга.</div>
                </div>
                @break

        @endswitch
    </div>

    {{-- ────────── LIGHTBOX (просмотр картинок) ──────────
         Открывается событием window:open-image с detail {src, name, dl}.
         Закрытие: Esc, клик по бэкдропу, кнопка «Закрыть».
         Inline-стили для критичной геометрии: защита от случая, когда
         Tailwind-бандл не пересобрали. Изображение центрируется через
         absolute + transform (а не flex), чтобы Alpine x-show не сбивал
         display root-элемента. --}}
    <div x-data="{ lbOpen: false, lbSrc: '', lbName: '', lbDl: '' }"
         x-on:open-image.window="lbOpen = true; lbSrc = $event.detail.src; lbName = $event.detail.name; lbDl = $event.detail.dl"
         x-on:keydown.escape.window="lbOpen = false">
        <div x-show="lbOpen"
             x-transition.opacity.duration.150ms
             style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.82); cursor: zoom-out;"
             x-on:click.self="lbOpen = false">
            <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; align-items: center; gap: 8px; z-index: 2;">
                <span style="color: rgba(255,255,255,0.92); font-size: 12px; font-family: var(--font-mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="lbName"></span>
                <a :href="lbDl" download class="btn btn-sm" x-on:click.stop>Скачать</a>
                <button type="button" class="btn btn-sm" x-on:click.stop="lbOpen = false">Закрыть</button>
            </div>
            <img :src="lbSrc" :alt="lbName"
                 style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: calc(100vw - 48px); max-height: calc(100vh - 80px); width: auto; height: auto; object-fit: contain; cursor: default; display: block; box-shadow: 0 8px 32px rgba(0,0,0,0.5);"
                 x-on:click.stop>
        </div>
    </div>
</div>
