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
    // displayedStatusBadge — composite: peak-milestone ИЛИ activity-overlay
    // «ход за нами» (📨 Клиент ответил). Operational status остаётся как
    // tooltip + используется для permissions ($req->status).
    $badge = $req->displayedStatusBadge;
    $statusDiverged = $badge['label'] !== $req->status->label();
    $statusChip = $badge['chipClass'];
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
            || auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'admin']);

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
                        <span class="chip {{ $statusChip }}"
                              @if($statusDiverged)
                                  title="Operational: {{ $req->status->label() }} · чип учитывает milestone (peak) + activity overlay"
                              @endif>
                            <span class="dot"></span>{{ $badge['icon'] ? $badge['icon'].' ' : '' }}{{ $badge['label'] }}
                        </span>
                        @if($statusDiverged)
                            <span class="text-[11px] text-[var(--fg-3)]" title="Operational статус — куда сейчас идёт работа">
                                → {{ $req->status->label() }}
                            </span>
                        @endif
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

                        {{-- Объединение: эта заявка — winner (приняла другие). --}}
                        @php $mergedFromCount = $req->mergedFrom()->count(); @endphp
                        @if($mergedFromCount > 0)
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm bg-sky-50 text-sky-700 text-[10.5px] font-semibold uppercase tracking-wider"
                                  title="С этой заявкой объединены — {{ $mergedFromCount }} шт.">
                                ⊌ объединённые
                                <span class="opacity-75">×{{ $mergedFromCount }}</span>
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
                    @php
                        $sticky = $this->sticky;
                        // Иконка + tooltip по типу sticky (catalog/client/text).
                        // Старые записи без kind рендерятся нейтрально.
                        $stickyKindLabel = match ($sticky['kind'] ?? null) {
                            'direct_mailbox' => '📬 личный ящик',
                            'catalog' => '📦 каталог',
                            'client' => '👤 клиент',
                            'text' => '🔤 текст',
                            default => null,
                        };
                        $stickyHoverTitle = match ($sticky['kind'] ?? null) {
                            'direct_mailbox' => 'Письмо пришло напрямую в личный ящик менеджера — назначение в обход round-robin',
                            'catalog' => 'Sticky сработал по совпадению catalog_item_id — тот же товар каталога уже у этого менеджера',
                            'client' => 'Sticky сработал по client_email — у менеджера есть открытая заявка от того же клиента',
                            'text' => 'Sticky сработал по парсеному артикулу/названию позиции',
                            default => 'Заявки, по которым AssignmentService прицепил эту через sticky',
                        };
                    @endphp
                    @if($sticky['links']->isNotEmpty())
                        <span class="flex items-center gap-1.5 flex-wrap"
                              title="{{ $stickyHoverTitle }}">
                            @if($stickyKindLabel)
                                <span class="inline-flex items-center text-[10.5px] font-semibold px-1.5 py-0.5 rounded-[3px] bg-violet-50 text-violet-700">{{ $stickyKindLabel }}</span>
                            @endif
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
                              title="{{ ($sticky['kind'] ?? null) === 'direct_mailbox' ? $stickyHoverTitle : 'Старая запись sticky — детали привязки не сохранены' }}">{{ $stickyKindLabel ?? 'sticky' }}</span>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </div>
                @php
                    // Phase 2.1 inheritance: chip направления.
                    // child  → «↻ наследник M-NNNN» (ссылка на parent).
                    // parent → «↻ продолжена в M-NNNN[, M-NNNN…]» (ссылки на детей).
                    // Иначе — слот скрыт.
                    $inhParent = $this->request->isInheritanceChild()
                        ? $this->request->inheritanceParent
                        : null;
                    $inhChildren = $this->request->isInheritanceParent()
                        ? $this->request->inheritanceChildren()->orderByDesc('created_at')->get(['id','internal_code','status'])
                        : collect();
                @endphp
                @if($inhParent || $inhChildren->isNotEmpty())
                <div class="flex flex-col gap-1 pr-4 border-r border-border-subtle min-w-0">
                    <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Наследование</span>
                    @if($inhParent)
                        <span class="flex items-center gap-1.5 flex-wrap"
                              title="Эта заявка — продолжение архивной {{ $inhParent->internal_code }} (LLM подтвердил сходство позиций)">
                            <span class="inline-flex items-center text-[10.5px] font-semibold px-1.5 py-0.5 rounded-[3px] bg-violet-50 text-violet-700">↻ наследник</span>
                            <a href="{{ route('requests.show', $inhParent) }}"
                               class="mono text-[12px] text-sky-700 hover:underline">{{ $inhParent->internal_code }}</a>
                        </span>
                    @else
                        <span class="flex items-center gap-1.5 flex-wrap"
                              title="У этой архивной заявки есть {{ $inhChildren->count() }} новых наследников">
                            <span class="inline-flex items-center text-[10.5px] font-semibold px-1.5 py-0.5 rounded-[3px] bg-violet-50 text-violet-700">↻ продолжена ×{{ $inhChildren->count() }}</span>
                            @foreach($inhChildren->take(3) as $child)
                                <a href="{{ route('requests.show', $child) }}"
                                   class="mono text-[12px] text-sky-700 hover:underline">{{ $child->internal_code }}</a>{{ ! $loop->last ? ',' : '' }}
                            @endforeach
                            @if($inhChildren->count() > 3)
                                <span class="text-fg-3 text-[12px]">+{{ $inhChildren->count() - 3 }}</span>
                            @endif
                        </span>
                    @endif
                </div>
                @endif
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

                {{-- Phase complexity: chip уровня сложности с разбивкой
                     по match_path (snapshot входной нагрузки на менеджера). --}}
                @php
                    $cLevel = $req->complexity_level;
                    $cScore = (int) ($req->complexity_score ?? 0);
                    if ($cLevel) {
                        $pathCounts = [];
                        foreach ($items as $it) {
                            if (! $it->is_active) continue;
                            $p = $it->match_path?->value ?? 'manual';
                            $pathCounts[$p] = ($pathCounts[$p] ?? 0) + 1;
                        }
                        $cTooltipLines = ["Score: {$cScore}"];
                        foreach (\App\Enums\MatchPath::cases() as $mp) {
                            $n = $pathCounts[$mp->value] ?? 0;
                            if ($n > 0) {
                                $cTooltipLines[] = $mp->label() . ': ' . $n . ' (×' . $mp->defaultWeight() . ')';
                            }
                        }
                        $cTooltip = implode("\n", $cTooltipLines);
                        $cChip = match ($cLevel->value) {
                            'easy' => 'bg-neutral-100 text-fg-3 border-border',
                            'normal' => 'bg-sky-50 text-sky-700 border-sky-200',
                            'hard' => 'bg-amber-50 text-amber-700 border-amber-300',
                            'very_hard' => 'bg-red-50 text-red-700 border-red-300',
                        };
                    }
                @endphp
                @if($cLevel)
                    <div class="flex flex-col gap-1 pl-4 border-l border-border-subtle">
                        <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">Сложность</span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-sm border text-[11.5px] font-medium whitespace-nowrap {{ $cChip }} w-fit"
                              title="{{ $cTooltip }}">
                            <span class="shrink-0">{{ $cLevel->icon() }}</span>
                            <span class="shrink-0">{{ $cLevel->label() }}</span>
                            <span class="font-mono text-[10.5px] opacity-70 shrink-0">{{ $cScore }}</span>
                        </span>
                    </div>
                @endif
                @php
                    // Phase 7 — Hero chip «📨 КП». Берём последний matched
                    // OutboundQuote (если КП было отправлено несколько раз —
                    // показываем самый свежий). null → chip не рисуется.
                    $latestQuote = $req->outboundQuotes->first();
                    $quoteMatchedCnt = null;
                    if ($latestQuote) {
                        $quoteMatchedCnt = $latestQuote->items
                            ->whereNotNull('matched_request_item_id')->count();
                    }
                @endphp
                @if($latestQuote)
                    <div class="flex flex-col gap-1 pl-4 border-l border-border-subtle">
                        <span class="uppercase tracking-wider text-[10.5px] font-semibold text-fg-3">
                            {{ $latestQuote->document_type?->value === 'outbound_invoice' ? 'Счёт' : 'КП' }}
                        </span>
                        <span class="text-fg-1 mono"
                              title="{{ $latestQuote->document_type?->label() }}{{ $latestQuote->document_number ? ' №' . $latestQuote->document_number : '' }}{{ $latestQuote->document_date ? ' от ' . $latestQuote->document_date->format('d.m.Y') : '' }} · сматчено {{ $quoteMatchedCnt }}/{{ $latestQuote->items->count() }} с позициями заявки">
                            @if($latestQuote->total_amount !== null)
                                {{ number_format((float) $latestQuote->total_amount, 2, '.', ' ') }} ₽
                            @else
                                <span class="text-fg-3">—</span>
                            @endif
                            <span class="text-fg-3 text-[11.5px]">·
                                {{ $quoteMatchedCnt }}/{{ $latestQuote->items->count() }}</span>
                        </span>
                    </div>
                @endif
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
                || $authUser?->hasAnyRole(['head_of_sales', 'director', 'admin']);
            // Отвечать клиенту — owner, acting, ИЛИ admin/РОП/директорат
            // (2026-05-28: расширено по запросу — админ должен мочь
            // отправить от имени любого менеджера, mailbox при этом
            // resolver берёт по assigned_user_id, т.е. письмо уходит
            // с ящика menager'а, не админа). Секретарь оставлен read-only.
            $canReply = $isOwner || $isDelegate
                || $authUser?->hasAnyRole(['head_of_sales', 'director', 'admin']);
            $canReassign = $authUser?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'admin']);
            $lastInbound = $thread->reverse()
                ->first(fn ($m) => $m->direction === \App\Enums\MailDirection::Inbound);
            $allowed = $req->status->allowedTransitions();
            $allow = fn (\App\Enums\RequestStatus $t) => in_array($t, $allowed, true);
            $RS = \App\Enums\RequestStatus::class;
        @endphp
        <div class="flex flex-col gap-2 min-w-[200px]">

            {{-- Ручной флаг attention. Менеджер/acting/РОП — toggle через
                 AttentionService::setManual/clearManual. Sticky: не затирается
                 recompute/onClientReplied/onManagerOpened. --}}
            @php
                $isManualSet = $req->attention_reason?->value === \App\Enums\AttentionReason::Manual->value;
                $canToggleManual = $canManage || $canReassign;
            @endphp
            @if($canToggleManual)
                @if($isManualSet)
                    <button type="button" wire:click="toggleManualAttention"
                            class="btn"
                            style="background:var(--amber-50);border-color:var(--amber-700);color:var(--amber-700)"
                            wire:confirm="Снять ручной флаг внимания с заявки?">
                        🚩 Снять флаг внимания
                    </button>
                @else
                    <button type="button" wire:click="toggleManualAttention"
                            class="btn">
                        🚩 Требует внимания
                    </button>
                @endif
            @endif

            {{-- Phase 4 (Foundation §7): pending AI-suggestion'ы DocumentDetector'а.
                 Рендерятся ВЫШЕ action-panel чтобы оператор увидел и принял
                 решение до основных кнопок переходов.
                 Phase E.2: inbound_clarification_response теперь показывается
                 отдельным баннером вверху страницы (детализированно с diff/bar),
                 поэтому здесь отфильтровываем — не дублируем. --}}
            {{-- Phase reply-suggestion: pending-позиции от парсера из reply'ев.
                 Vision увидел позицию в ответе клиента, но confidence ниже
                 auto-apply порога (или fuzzy-похожесть с существующей). Менеджер
                 должен apply/reject. --}}
            @php
                $pendingPositions = \App\Models\RequestItem::query()
                    ->where('request_id', $req->id)
                    ->where('suggestion_status', 'pending')
                    ->orderBy('position')
                    ->get();
            @endphp
            @if($pendingPositions->isNotEmpty() && ($canManage || $canReassign))
                <div class="ds-card p-3 text-[12px] mb-2"
                     style="background: var(--amber-50); border-color: var(--amber-700);">
                    <div class="font-semibold text-[var(--amber-800)] mb-2 flex items-center gap-1.5">
                        💡 Парсер увидел в ответе клиента {{ $pendingPositions->count() }}
                        {{ trans_choice('новую позицию|новые позиции|новых позиций', $pendingPositions->count()) }}
                    </div>
                    <div class="space-y-2">
                        @foreach($pendingPositions as $pp)
                            <div class="p-2 rounded bg-white border border-[var(--amber-700)]/30">
                                <div class="flex items-baseline gap-2 text-[12px] flex-wrap">
                                    @if($pp->parsed_article)
                                        <span class="mono font-medium text-fg-1">{{ $pp->parsed_article }}</span>
                                    @endif
                                    @if($pp->parsed_brand)
                                        <span class="text-fg-2">{{ $pp->parsed_brand }}</span>
                                    @endif
                                    <span class="text-fg-1">{{ $pp->parsed_name ?: '—' }}</span>
                                    @if($pp->parsed_qty)
                                        <span class="text-fg-3">· {{ rtrim(rtrim((string) $pp->parsed_qty, '0'), '.') }} шт</span>
                                    @endif
                                    <span class="flex-1"></span>
                                    @if($pp->suggestion_confidence !== null)
                                        <span class="mono text-[10.5px] px-1.5 py-0.5 rounded-sm bg-[var(--amber-100)] text-[var(--amber-800)]"
                                              title="Уверенность парсера">
                                            {{ (int) round($pp->suggestion_confidence * 100) }}%
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-1.5 mt-2">
                                    <button type="button"
                                            wire:click="applyPositionSuggestion({{ $pp->id }})"
                                            class="btn btn-sm btn-primary text-[11px]">✓ Подтвердить</button>
                                    <button type="button"
                                            wire:click="rejectPositionSuggestion({{ $pp->id }})"
                                            class="btn btn-sm text-[11px]">✕ Отклонить</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

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
                        @if($req->merged_into_id)
                            @php $mergedInto = \App\Models\Request::find($req->merged_into_id); @endphp
                            @if($mergedInto)
                                <div class="mt-2 pt-2 border-t border-red-300/40 text-[12px]">
                                    <span class="text-fg-3">Объединена с:</span>
                                    <a href="{{ route('requests.show', $mergedInto) }}" wire:navigate
                                       class="mono text-[var(--accent)] hover:underline">{{ $mergedInto->internal_code }}</a>
                                    @if($req->merged_at)
                                        <span class="text-fg-4 text-[11px]">· {{ $req->merged_at->format('d.m.Y H:i') }}</span>
                                    @endif
                                </div>
                            @endif
                        @endif
                    @endif
                </div>
                @if($canReassign)
                    <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" lazy />
                @endif

                {{-- Phase 2.3 — ручная реанимация closed_lost (только она;
                     closed_won не реанимируется — там сделка состоялась).
                     Permission: owner / acting / privileged. Менеджер сохраняется,
                     re-assessment ассайни не запускается. --}}
                @if($req->status === $RS::ClosedLost && ($canManage || $canReassign))
                    <button type="button"
                            wire:click="manualReanimate"
                            wire:confirm="Вернуть заявку в работу? Статус сменится на «В работе», менеджер останется тот же."
                            class="btn btn-sm"
                            title="Реанимировать — typical случай: клиент молчал, потом передумал и попросил обновить КП/счёт">
                        ↻ Реанимировать
                    </button>
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
                    <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" lazy />
                @endif

            {{-- Активные статусы. --}}
            @else
                {{-- Главные действия зависят от статуса.
                     Assigned/New статусы НЕ показывают кнопку «Начать работу» —
                     это работает auto-transition в Detail::mount (открытие
                     карточки = начало работы, implicit-state).
                     Кнопка остаётся для возврата из ожидающих/quoted состояний. --}}
                @if(
                    $allow($RS::InProgress)
                    && $req->status !== $RS::InProgress
                    && $req->status !== $RS::Assigned
                    && $req->status !== $RS::New
                )
                    <button type="button" wire:click="transitionStatus('in_progress')"
                            class="btn btn-primary"
                            @disabled(! $canManage)>
                        @if($req->status === $RS::AwaitingClientClarification)
                            ✓ Клиент ответил
                        @else
                            ↩ Вернуться к работе
                        @endif
                    </button>
                @endif

                {{-- Ручные переходы вперёд по pipeline.
                     Изначально были убраны в надежде на auto-detect
                     (OutboundDocumentDetector / InboundIntentClassifier), но
                     практика показала: AI промахивается, заявки застревают
                     в Assigned/InProgress (см. 2026-05-22 — 11 КП в Assigned).
                     Менеджеру нужен ручной escape hatch.

                     AI-плашка над action-panel остаётся для случаев когда
                     detector сработал — менеджер может «Применить» одним
                     кликом. Здесь же — ручной путь когда AI молчит. --}}

                @if($allow($RS::Quoted) && $req->status !== $RS::Quoted)
                    <button type="button" wire:click="transitionStatus('quoted')"
                            class="btn btn-sm"
                            @disabled(! $canManage)
                            title="КП отправлено клиенту. Если auto-detect не сработал.">📤 КП отправлено</button>
                @endif

                @if($allow($RS::AwaitingInvoice) && $req->status !== $RS::AwaitingInvoice)
                    <button type="button" wire:click="transitionStatus('awaiting_invoice')"
                            class="btn btn-sm"
                            @disabled(! $canManage)
                            title="Клиент попросил выставить счёт. Если AI не уловил intent.">📋 Запросил счёт</button>
                @endif

                {{-- 📋 Выставить счёт — открывает диалог с вводом номера,
                     даты, срока действия (5 раб.дн по умолчанию).
                     Создаёт Invoice + переводит заявку в Invoiced.
                     Показывается из любого статуса, откуда разрешён переход
                     в Invoiced (карта переходов из RequestStatus). --}}
                @if($allow($RS::Invoiced))
                    <button type="button"
                            wire:click="$dispatch('open-issue-invoice-dialog')"
                            class="btn btn-sm"
                            @disabled(! $canManage)>📋 Выставить счёт</button>
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
                        <livewire:requests.reassign-dialog :request="$req" wire:key="reassign-{{ $req->id }}" lazy />
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

                {{-- Disabled placeholder'ы убраны:
                     «🧾 Сформировать КП» — функционал в табе «КП» (Editor).
                     «🔄 Refresh цен» — Phase 3, появится когда будет готов. --}}
            @endif

            {{-- Phase 4: счета заявки → отдельный таб «Счета».
                 Таб подсвечивается по состоянию: красный при overdue,
                 amber при pending, emerald при только-оплаченных. --}}

            {{-- Слияние дубликата (Phase merge). Owner/acting/privileged.
                 Кнопка показывается только когда заявка active (есть с чем сливать). --}}
            @if(($canManage || $canReassign) && ! in_array($req->status, [$RS::Paused, $RS::ClosedWon, $RS::ClosedLost, $RS::Pending, $RS::Paid], true))
                <livewire:requests.merge-dialog :request="$req" wire:key="merge-{{ $req->id }}" lazy />
            @endif

            {{-- Phase 2.1 — отвязать наследование (только для child-заявок).
                 Доступно owner / acting / privileged. История item-links
                 сохраняется (is_active=false), статус child обнуляется. --}}
            @if(($canManage || $canReassign) && $req->isInheritanceChild())
                <button type="button"
                        wire:click="unlinkInheritance"
                        wire:confirm="Отвязать эту заявку от архивной {{ $req->inheritanceParent?->internal_code }}? Связи позиций будут деактивированы (история сохранится)."
                        class="btn btn-sm"
                        title="Эта заявка ошибочно помечена как наследник — отвязать">
                    🔗 Отвязать наследование
                </button>
            @endif

            {{-- Модальные диалоги (single-instance per Detail).
                 lazy=true — Livewire не hydrate'ит компонент при mount страницы,
                 рендерит placeholder <div>. State грузится только при первом
                 dispatch'е open-event. Экономия: ~70-100 КБ raw payload на
                 каждый dialog × 5 = ~400-500 КБ за один Detail page.
            --}}
            <livewire:requests.pause-dialog :request="$req" wire:key="pause-{{ $req->id }}" lazy />
            <livewire:requests.postpone-dialog :request="$req" wire:key="postpone-{{ $req->id }}" lazy />
            <livewire:requests.close-lost-dialog :request="$req" wire:key="close-lost-{{ $req->id }}" lazy />
            <livewire:requests.issue-invoice-dialog :request="$req" wire:key="issue-invoice-{{ $req->id }}" lazy />
            {{-- Phase 7: ручной доматчинг строк КП к позициям заявки. --}}
            <livewire:requests.quotes.match-request-item-dialog :request="$req" wire:key="quote-match-{{ $req->id }}" lazy />
        </div>
    </div>

    {{-- ────────── TABS ────────── --}}
    <div class="flex border border-border bg-surface px-4 rounded-t-md" style="border-bottom-color: var(--border-subtle)">
        @foreach($tabs as $key => $meta)
            @php
                $active = $tab === $key;
                // Phase 4 — state-based подсветка таба (используется для invoices).
                // Возможные state: 'overdue' (красный), 'pending' (amber),
                // 'paid' (emerald), 'closed' (нейтральный gray), null (без state).
                $state = $meta['state'] ?? null;
                $stateTextCls = match ($state) {
                    'overdue' => $active ? 'text-red-700' : 'text-red-600',
                    'pending' => $active ? 'text-amber-700' : 'text-amber-600',
                    'paid'    => $active ? 'text-emerald-700' : 'text-emerald-600',
                    default   => null,
                };
                $stateBorderCls = match ($state) {
                    'overdue' => $active ? 'border-red-500' : 'border-transparent',
                    'pending' => $active ? 'border-amber-500' : 'border-transparent',
                    'paid'    => $active ? 'border-emerald-500' : 'border-transparent',
                    default   => null,
                };
                $stateBadgeCls = match ($state) {
                    'overdue' => 'bg-red-50 text-red-700',
                    'pending' => 'bg-amber-50 text-amber-700',
                    'paid'    => 'bg-emerald-50 text-emerald-700',
                    default   => null,
                };
            @endphp
            <button type="button"
                    @if(! $meta['disabled']) wire:click="setTab('{{ $key }}')" @else disabled title="Доступно в Phase 2" @endif
                    @if($state) title="Состояние: {{ ['overdue'=>'просрочены','pending'=>'ожидают оплаты','paid'=>'оплачены','closed'=>'все закрыты'][$state] ?? $state }}" @endif
                    class="-mb-px px-3.5 py-2.5 text-[12.5px] inline-flex items-center gap-1.5 border-b-2 transition-colors
                           {{ $active
                              ? ($stateTextCls ?: 'text-fg-1') . ' font-semibold ' . ($stateBorderCls ?: 'border-accent')
                              : ($stateTextCls ?: 'text-fg-3') . ' border-transparent ' . ($meta['disabled'] ? 'opacity-55 cursor-not-allowed' : 'hover:text-fg-1 cursor-pointer') }}">
                @if($state === 'overdue')<span class="inline-block w-1.5 h-1.5 rounded-full bg-red-500"></span>@endif
                {{ $meta['label'] }}
                @if($meta['count'] !== null)
                    <span class="text-[10.5px] font-semibold px-1.5 rounded-full
                                 {{ $stateBadgeCls ?: ($active ? 'bg-red-50 text-red-700' : 'bg-neutral-100 text-fg-2') }}">
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
                @php
                    // Справочно из файлов — что AttachmentMetaExtractionService
                    // нашёл в xlsx/pdf/docx помимо позиций (серийник лифта,
                    // модель, объект, договор, контактное лицо, желаемая дата).
                    // Источник: requests.parsing_meta.attachment_extracted[].
                    $_pm = is_array($req->parsing_meta) ? $req->parsing_meta : [];
                    $_attExtracted = $_pm['attachment_extracted'] ?? [];

                    // Карта человекочитаемых меток полей.
                    $_metaLabels = [
                        'lift_serial' => 'Зав. номер',
                        'lift_model' => 'Модель лифта',
                        'lift_brand' => 'Бренд',
                        'object_address' => 'Адрес объекта',
                        'object_name' => 'Объект',
                        'contract_number' => 'Договор',
                        'desired_date' => 'Желаемая дата',
                        'contact_person' => 'Контакт',
                        'contact_phone' => 'Телефон',
                        'notes' => 'Замечания',
                    ];
                @endphp

                @if(! empty($_attExtracted))
                    <div class="ds-card mb-4 border-emerald-300">
                        <div class="ds-card-header bg-emerald-50">
                            <h3 class="text-emerald-900">Справочно из файлов</h3>
                            <span class="text-[10.5px] font-semibold text-emerald-900 bg-emerald-100 px-1.5 py-0.5 rounded-full">{{ count($_attExtracted) }}</span>
                            <span class="flex-1"></span>
                            <span class="text-[11.5px] text-emerald-800">Извлечено LLM из вложений (не входит в список позиций)</span>
                        </div>
                        <div class="divide-y divide-emerald-100">
                            @foreach($_attExtracted as $_blk)
                                @php
                                    $_fields = $_blk['fields'] ?? [];
                                    $_links = $_fields['links'] ?? [];
                                @endphp
                                <div class="px-[18px] py-2.5 text-[12.5px]">
                                    <div class="text-[11px] text-fg-3 mb-1.5">
                                        Файл: <span class="mono text-fg-1">{{ $_blk['filename'] ?? '—' }}</span>
                                    </div>
                                    <div class="grid gap-x-4 gap-y-1" style="grid-template-columns: 130px 1fr">
                                        @foreach($_metaLabels as $_k => $_lbl)
                                            @if(! empty($_fields[$_k]))
                                                <div class="text-fg-3 text-[11.5px]">{{ $_lbl }}</div>
                                                <div class="text-fg-1">{{ $_fields[$_k] }}</div>
                                            @endif
                                        @endforeach
                                        @if(! empty($_links) && is_array($_links))
                                            <div class="text-fg-3 text-[11.5px]">Ссылки</div>
                                            <div class="flex flex-col gap-0.5">
                                                @foreach($_links as $_u)
                                                    <a href="{{ $_u }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline mono text-[11.5px] truncate">{{ $_u }}</a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

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
                                            // Inline-кнопки «↩ Ответить / ↩↩ Ответить всем»
                                            // под каждым сообщением треда. Используем $canReply
                                            // (= owner / acting / admin / РОП / директорат)
                                            // вместо старого hardcoded == assigned_user_id.
                                            $canReplyHere = $canReply;
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
                                            @php
                                                // Галерея картинок этого письма для листания в лайтбоксе:
                                                // ← / → переключают между картинками одного письма.
                                                $msgImgs = $msg->attachments->filter(fn ($a) => $isImageAttachment($a))->values();
                                                $msgGallery = $msgImgs->map(fn ($a) => [
                                                    'src' => route('attachments.preview', $a),
                                                    'name' => $a->filename,
                                                    'dl' => route('attachments.download', $a),
                                                ])->all();
                                                $msgImgIdx = 0;
                                            @endphp
                                            <div class="mt-3 flex flex-wrap gap-2"
                                                 x-data="{ items: @js($msgGallery) }">
                                                @foreach($msg->attachments as $att)
                                                    @php
                                                        $isImg = $isImageAttachment($att);
                                                        $previewUrl = route('attachments.preview', $att);
                                                        $downloadUrl = route('attachments.download', $att);
                                                    @endphp
                                                    @if($isImg)
                                                        {{-- Image thumbnail → клик открывает лайтбокс с
                                                             пролистыванием всех картинок этого письма. --}}
                                                        <button type="button"
                                                                x-on:click="$dispatch('open-image', { items: items, index: {{ $msgImgIdx }} })"
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
                                                        @php $msgImgIdx++; @endphp
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

                            {{-- Phase 1.9 — Compose / Reply form.
                                 $canReply = owner / acting / admin / РОП / директорат
                                 (см. блок «Actions» в Aside). Письмо уходит с
                                 ящика assigned-менеджера через OutgoingMailboxResolver,
                                 даже если отправляет админ. --}}
                            @if($canReply)
                                <div class="px-[18px] py-3.5 bg-surface-2 border-t border-border">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[12px] text-fg-3">
                                            Ответ клиенту через MyLift — копия сохранится в Sent ящика
                                            @if(! $isOwner && ! $isDelegate && $req->assignedUser)
                                                <span class="text-fg-2">{{ $req->assignedUser->name }}</span>.
                                                <span class="text-amber-700">(вы отправляете от его имени)</span>
                                            @else
                                                ящика.
                                            @endif
                                        </span>
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
                                    ({{ $req->assignedUser?->name ?? '— не назначен —' }}),
                                    acting (делегат) или admin/РОП/директорат.
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

                @php
                    // Дедуп-трасса: RequestItemPersister аппендит в
                    // requests.parsing_meta.dedup_dropped[] каждую съеденную
                    // dedupeWithinList дубль-строку. Менеджеру показываем
                    // компактный баннер «N позиций было схлопнуто», чтобы
                    // он мог сверить с исходным файлом — возможно клиент
                    // случайно поставил одинаковые артикулы у разных
                    // позиций (СРЕДНИЕ/КОНЕЧНЫЕ ЭТАЖИ, Р1/Р2 кнопки).
                    $parsingMeta = is_array($req->parsing_meta) ? $req->parsing_meta : [];
                    $dedupDropped = $parsingMeta['dedup_dropped'] ?? [];
                @endphp

                @if(! empty($dedupDropped))
                    <div class="ds-card mb-3 border-sky-300">
                        <div class="ds-card-header bg-sky-50">
                            <h3 class="text-sky-900">Парсер схлопнул дубли · количество просуммировано</h3>
                            <span class="text-[10.5px] font-semibold text-sky-900 bg-sky-100 px-1.5 py-0.5 rounded-full">{{ count($dedupDropped) }}</span>
                            <span class="flex-1"></span>
                            <span class="text-[11.5px] text-sky-800">Одинаковый артикул + invoice_index у разных строк исходника — оставлена одна позиция, qty просуммированы. Проверьте — возможно нужно расщепить вручную.</span>
                        </div>
                        <details class="px-[18px] py-2 text-[12.5px]">
                            <summary class="cursor-pointer text-sky-800 select-none py-1">Показать детали ({{ count($dedupDropped) }})</summary>
                            <div class="divide-y divide-sky-100 mt-1">
                                @foreach($dedupDropped as $d)
                                    @php
                                        $mergedPos = $d['merged_into_position'] ?? null;
                                        $winner = $mergedPos ? $items->firstWhere('position', (int) $mergedPos) : null;
                                        $qtyEaten = $d['qty'] ?? null;
                                        $qtyOrigWin = $d['qty_original_winner'] ?? null;
                                        $qtySummed = $d['qty_summed_into'] ?? null;
                                    @endphp
                                    <div class="py-2 flex items-start gap-3 flex-wrap">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-fg-3 text-[11px]">Съедено:</span>
                                                <span class="font-medium text-fg-1">{{ \Illuminate\Support\Str::limit($d['name'] ?? '—', 80) }}</span>
                                                @if(! empty($d['article']))
                                                    <span class="mono text-[11.5px] text-fg-2">{{ \Illuminate\Support\Str::limit($d['article'], 60) }}</span>
                                                @endif
                                                <span class="text-[11px] text-fg-3">× {{ $qtyEaten ?? '?' }}</span>
                                            </div>
                                            <div class="mt-1 text-[11px] text-fg-3 flex items-center gap-2 flex-wrap">
                                                <span>Источник: <span class="mono">{{ $d['source'] ?? '—' }}</span></span>
                                                <span>·</span>
                                                <span>Слито в позицию:
                                                    @if($winner)
                                                        <span class="mono text-fg-1">#{{ $winner->position }}</span>
                                                        <span class="text-fg-2">{{ \Illuminate\Support\Str::limit($winner->parsed_name, 50) }}</span>
                                                    @else
                                                        <span class="mono">#{{ $mergedPos ?? '?' }}</span>
                                                    @endif
                                                </span>
                                                @if($qtyOrigWin !== null && $qtySummed !== null)
                                                    <span>·</span>
                                                    <span class="inline-flex items-center gap-1">
                                                        <span class="text-fg-3">qty:</span>
                                                        <span class="mono text-fg-2">{{ $qtyOrigWin }}</span>
                                                        <span class="text-fg-3">+</span>
                                                        <span class="mono text-fg-2">{{ $qtyEaten ?? '?' }}</span>
                                                        <span class="text-fg-3">→</span>
                                                        <span class="mono font-semibold text-sky-800">{{ $qtySummed }}</span>
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </div>
                @endif

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
                    // 2026-05-21: используем единый Request::isAccessibleBy() —
                    // покрывает assigned, ROP/director/secretary И acting-менеджера
                    // через активную делегацию. Раньше acting-через-делегирование
                    // не получал slots/chips (canEditItems=false) — фикс UX.
                    $canEditItems = $req->isAccessibleBy(auth()->user());
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
                            {{-- 04c: .sblock с .sbh (gradient violet→surface header)
                                 + список .scard (grid 1fr/220px, target/diff/reason слева,
                                 confbar + actions справа). --}}
                            @if($allPendingSuggestions->isNotEmpty() && $canEditItems)
                                <div class="mt-3 rounded-md bg-surface overflow-hidden mb-3.5"
                                     style="border: 1px solid oklch(82% 0.10 280)">
                                    {{-- HEADER (.sbh) --}}
                                    <div class="flex items-center gap-3 px-[18px] py-3.5 border-b border-border-subtle"
                                         style="background: linear-gradient(180deg, oklch(97% 0.025 280) 0%, var(--bg-surface) 100%)">
                                        <h2 class="m-0 flex items-center gap-2 font-semibold text-fg-1" style="font-size:14px;line-height:1.2">
                                            <span>Предложенные уточнения</span>
                                            <span class="inline-flex items-baseline px-1.5 py-0.5 rounded-full text-white font-bold mono text-[11px] leading-none"
                                                  style="background: oklch(58% 0.18 280)">{{ $allPendingSuggestions->count() }}</span>
                                        </h2>
                                        <span class="text-[12px] text-fg-3">· LLM нашёл в reply'е уточнения к существующим позициям</span>
                                        <span class="flex-1"></span>
                                        <button type="button"
                                                wire:click="dismissAllEnrichments"
                                                wire:confirm="Отклонить все {{ $allPendingSuggestions->count() }} предложений?"
                                                class="btn btn-sm">Отклонить все</button>
                                        <button type="button"
                                                wire:click="applyAllEnrichments"
                                                wire:confirm="Применить все {{ $allPendingSuggestions->count() }} предложений?"
                                                class="btn btn-sm btn-primary">Применить все ({{ $allPendingSuggestions->count() }})</button>
                                    </div>

                                    {{-- SCARDS --}}
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
                                            $_slots = $slotResolver->resolve($_it);
                                            $_slotsByKey = collect($_slots)->keyBy('key');

                                            $_currentValue = null;
                                            $_targetLabel = $_field;
                                            $_diffLabel = 'Изменение:';
                                            if ($_field === 'parsed_brand') {
                                                $_currentValue = $_it->brand?->name ?: ($_it->parsed_brand ?: null);
                                                $_targetLabel = 'бренд';
                                            } elseif ($_field === 'parsed_article') {
                                                $_currentValue = $_it->parsed_article ?: null;
                                                $_targetLabel = 'артикул';
                                                $_diffLabel = $_currentValue ? 'Подтверждение:' : 'Заполнение:';
                                            } elseif ($_field === 'parsed_qty') {
                                                $_currentValue = $_it->parsed_qty
                                                    ? rtrim(rtrim((string) $_it->parsed_qty, '0'), '.') . ' ' . ($_it->parsed_unit ?: 'шт.')
                                                    : null;
                                                $_targetLabel = 'кол-во';
                                            } elseif (str_starts_with($_field, 'kb:')) {
                                                $_slug = substr($_field, 3);
                                                $_extracted = is_array($_it->quality_assessment_payload['extracted_parameters'] ?? null)
                                                    ? $_it->quality_assessment_payload['extracted_parameters'] : [];
                                                $_currentValue = $_extracted[$_slug] ?? null;
                                                $_targetLabel = mb_strtolower($_slotsByKey[$_field]['label'] ?? $_slug);
                                                $_diffLabel = $_currentValue ? 'Изменение:' : 'Заполнение:';
                                            }
                                        @endphp
                                        <div class="grid items-center gap-[18px] px-[18px] py-4 border-t border-border-subtle first:border-t-0"
                                             style="grid-template-columns: minmax(0, 1fr) 220px"
                                             wire:key="sugg-{{ $_it->id }}-{{ $_sid }}">
                                            {{-- LEFT: target / diff / reason --}}
                                            <div class="flex flex-col gap-2.5 min-w-0">
                                                {{-- target --}}
                                                <div class="flex items-center gap-2 flex-wrap" style="font-size:12.5px">
                                                    <span class="text-fg-3 uppercase font-medium" style="font-size:10.5px;letter-spacing:.04em">К позиции</span>
                                                    <span class="font-semibold mono text-fg-3 bg-surface-2 border border-border px-1.5 py-0.5 rounded-[4px]" style="font-size:12px">#{{ $_it->position }}</span>
                                                    <span class="font-medium text-fg-1">{{ $_it->parsed_name ?: '(без названия)' }}</span>
                                                    @if($_it->brand)
                                                        <span class="font-semibold bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded-[3px] uppercase" style="font-size:11px;letter-spacing:.02em">{{ $_it->brand->name }}</span>
                                                    @elseif($_it->parsed_brand)
                                                        <span class="font-semibold bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded-[3px] uppercase" style="font-size:11px;letter-spacing:.02em">{{ $_it->parsed_brand }}</span>
                                                    @endif
                                                    <span class="text-fg-3">·</span>
                                                    <span class="text-fg-3">{{ rtrim(rtrim((string) $_it->parsed_qty, '0'), '.') ?: '—' }} {{ $_it->parsed_unit ?: 'шт.' }}</span>
                                                </div>

                                                {{-- diff: было → будет --}}
                                                <div class="flex items-center gap-2.5 flex-wrap" style="font-size:12.5px">
                                                    <span class="text-fg-3 uppercase font-medium" style="font-size:10.5px;letter-spacing:.04em">{{ $_diffLabel }}</span>
                                                    {{-- was slot --}}
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-neutral-100 border border-border">
                                                        <span class="text-fg-3 uppercase font-medium" style="font-size:10.5px;letter-spacing:.04em">{{ $_targetLabel }}</span>
                                                        @if($_currentValue !== null)
                                                            <span class="font-semibold text-fg-3 line-through" style="font-size:12.5px">{{ $_currentValue }}</span>
                                                        @else
                                                            <span class="text-fg-3 italic" style="font-size:12.5px">пусто</span>
                                                        @endif
                                                    </span>
                                                    <span class="mono font-semibold text-fg-3" style="font-size:16px;line-height:1">→</span>
                                                    {{-- now slot --}}
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50"
                                                          style="border: 1px solid oklch(82% 0.10 160)">
                                                        <span class="text-fg-3 uppercase font-medium" style="font-size:10.5px;letter-spacing:.04em">{{ $_targetLabel }}</span>
                                                        <span class="font-semibold mono text-emerald-700" style="font-size:12.5px">{{ $_newVal }}</span>
                                                    </span>
                                                </div>

                                                {{-- reason: tag + quote --}}
                                                @if($_quote !== '')
                                                    <div class="flex items-start gap-2 text-fg-3 italic" style="font-size:12px;line-height:1.5">
                                                        <span class="shrink-0 font-semibold mono not-italic px-1.5 py-0.5 rounded-[3px] bg-emerald-50 text-emerald-700"
                                                              style="font-size:11px;line-height:1.4;border:1px solid oklch(86% 0.08 160)">match · {{ $_confPct }}%</span>
                                                        <span>«{{ \Illuminate\Support\Str::limit($_quote, 240) }}»</span>
                                                    </div>
                                                @endif
                                            </div>

                                            {{-- RIGHT: conf bar + actions --}}
                                            <div class="flex flex-col gap-1.5">
                                                <div class="text-fg-3 uppercase font-medium text-center" style="font-size:10.5px;letter-spacing:.04em">Уверенность LLM</div>
                                                <div class="h-1 rounded-full overflow-hidden bg-neutral-100">
                                                    <div class="h-full" style="width: {{ $_confPct }}%; background: oklch(58% 0.18 280)"></div>
                                                </div>
                                                <div class="text-center mono font-semibold" style="font-size:12px; color: oklch(46% 0.16 280)">{{ $_confPct }}%</div>

                                                <div class="flex flex-col gap-1.5 mt-1.5">
                                                    <button type="button"
                                                            wire:click="applyEnrichmentSuggestion({{ $_it->id }}, '{{ $_sid }}')"
                                                            wire:confirm="Применить «{{ $_newVal }}»?"
                                                            class="btn btn-primary btn-sm w-full">✓ Применить</button>
                                                    <button type="button"
                                                            wire:click="dismissEnrichmentSuggestion({{ $_it->id }}, '{{ $_sid }}')"
                                                            class="btn btn-sm w-full">Отклонить</button>
                                                    <div x-data="{ open: false }" class="relative text-center" @click.outside="open = false">
                                                        <a @click="open = !open"
                                                           class="text-sky-700 cursor-pointer hover:underline"
                                                           style="font-size:11.5px;text-decoration:underline dashed;text-underline-offset:3px">правка вручную</a>
                                                        <div x-show="open" x-cloak x-transition
                                                             class="absolute right-0 top-full mt-1 z-30 w-[220px] py-1 bg-surface border border-border rounded-md shadow-lg text-left text-[12px]">
                                                            <div class="px-3 py-1 text-fg-3 uppercase tracking-wider font-semibold border-b border-border-subtle"
                                                                 style="font-size:10.5px">Применить в другой слот:</div>
                                                            @foreach($_slots as $_sl)
                                                                @php $_disabled = $_sl['status'] === 'filled'; @endphp
                                                                <button type="button"
                                                                        @click="open = false"
                                                                        @if(! $_disabled)
                                                                            wire:click="applyEnrichmentToSlot({{ $_it->id }}, '{{ $_sid }}', '{{ $_sl['key'] }}')"
                                                                            wire:confirm="Записать «{{ $_newVal }}» в «{{ $_sl['label'] }}»?"
                                                                        @endif
                                                                        @disabled($_disabled)
                                                                        class="block w-full text-left px-3 py-1.5 {{ $_disabled ? 'text-fg-4 cursor-not-allowed' : 'hover:bg-sky-50 text-fg-1' }}">
                                                                    <span>{{ $_sl['label'] }}</span>
                                                                    @if($_disabled)<span class="text-fg-3 text-[10.5px]">· заполнен</span>@endif
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
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

                            @php
                                // Галерея ВСЕХ image-вложений письма заявки —
                                // для листания в лайтбоксе (← / →).
                                // Используем все картинки письма, а не только
                                // привязанные к позициям image_attachment_id,
                                // чтобы менеджер мог пролистать весь набор фото
                                // даже если Vision привязал только одно.
                                $positionsGallery = [];
                                $positionsImgIndex = []; // image_attachment_id → idx
                                if ($email) {
                                    foreach ($email->attachments as $_att) {
                                        if (! $isImageAttachment($_att)) continue;
                                        $positionsImgIndex[$_att->id] = count($positionsGallery);
                                        $positionsGallery[] = [
                                            'src' => route('attachments.preview', $_att),
                                            'name' => $_att->filename,
                                            'dl' => route('attachments.download', $_att),
                                        ];
                                    }
                                }
                                // Карта item.id → idx в галерее (через
                                // image_attachment_id, если есть).
                                $positionsItemIdx = [];
                                foreach ($items as $_pos) {
                                    $aid = $_pos->image_attachment_id;
                                    if ($aid !== null && isset($positionsImgIndex[$aid])) {
                                        $positionsItemIdx[$_pos->id] = $positionsImgIndex[$aid];
                                    }
                                }
                            @endphp
                            <div class="p-[8px] bg-app">
                            @php
                                // Phase 2.1 — Inheritance link для текущей позиции.
                                // child  → одна ссылка на parent-item.
                                // parent → коллекция child-links (несколько детей могут ссылаться).
                                $inhLinksMap = $this->inheritanceItemLinks;
                                $isInhChild = $req->isInheritanceChild();
                            @endphp
                            @foreach($items as $item)
                                @php
                                    $slots = $slotResolver->resolve($item);
                                    $inhLinkForItem = $inhLinksMap->get($item->id);
                                @endphp
                                @include('livewire.requests.items._position-card', [
                                    'item' => $item,
                                    'slots' => $slots,
                                    'isImageAttachment' => $isImageAttachment,
                                    'canEditItems' => $canEditItems,
                                    'items' => $items,
                                    'expanded' => (bool) ($expandedPositions[$item->id] ?? false),
                                    'galleryIndex' => $positionsItemIdx[$item->id] ?? null,
                                    'galleryItems' => $positionsGallery,
                                    'inheritanceLink' => $inhLinkForItem,
                                    'inheritanceIsChild' => $isInhChild,
                                ])
                            @endforeach
                            </div>

                            {{-- 04c .htimeline — раунды batches'ов: одна строка
                                 на batch (синий dot, «Морозов А. спросил по позициям:
                                 ... + qchips»). Если batch answered — отдельная row.ans
                                 с зелёным dot + цитата ответа в .quote блоке +
                                 qchips matched. --}}
                            @php
                                $_clarFilter = fn ($a) => ! in_array(mb_strtolower(trim((string) $a)), ['', 'null', 'none', '—', '-', 'n/a'], true);
                                $_allClarBatches = $items
                                    ->flatMap(fn ($i) => $i->clarificationQuestions)
                                    ->filter(fn ($q) => $q->batch !== null)
                                    ->groupBy(fn ($q) => $q->batch->id)
                                    ->map(fn ($qs) => $qs->sortBy('id'));
                                $_allClarBatches = $_allClarBatches->sortBy(
                                    fn ($qs) => $qs->first()?->batch?->sent_at?->timestamp ?? $qs->first()?->id
                                );
                                $_totalBatches = $_allClarBatches->count();
                                $_allAnswered = $_totalBatches > 0
                                    && $_allClarBatches->every(fn ($qs) => $qs->every(fn ($q) => $_clarFilter($q->answer)));
                            @endphp
                            @if($_allClarBatches->isNotEmpty())
                                <div class="mt-4 rounded-md bg-surface border border-border overflow-hidden mb-3.5">
                                    {{-- HEADER --}}
                                    <div class="flex items-center gap-2.5 px-[18px] py-3 border-b border-border-subtle">
                                        <h3 class="m-0 font-semibold text-fg-1" style="font-size:13px;line-height:1.2">
                                            История уточнений · {{ $_totalBatches }} {{ \Illuminate\Support\Str::plural('раунд', $_totalBatches) }}
                                        </h3>
                                        <span class="flex-1"></span>
                                        <span class="text-fg-3" style="font-size:12px">
                                            @if($_allAnswered)
                                                все ответы получены
                                            @else
                                                {{ $_allClarBatches->filter(fn ($qs) => $qs->every(fn ($q) => $_clarFilter($q->answer)))->count() }}/{{ $_totalBatches }} раундов отвечено
                                            @endif
                                            · <a href="#" wire:click.prevent="setTab('thread')" class="text-sky-700 hover:underline">открыть переписку →</a>
                                        </span>
                                    </div>

                                    {{-- ROWS — один раунд = один batch --}}
                                    @foreach($_allClarBatches as $_batchId => $_batchQs)
                                        @php
                                            $_b = $_batchQs->first()->batch;
                                            $_isSent = $_b && in_array($_b->status, ['sent', 'answered'], true);
                                            $_batchAnswered = $_batchQs->every(fn ($q) => $_clarFilter($q->answer));
                                            $_managerName = $_b->createdBy?->name ?: 'Менеджер';

                                            // Список chips «#N <slot-label>» для вопросов
                                            $_chips = [];
                                            foreach ($_batchQs as $_q) {
                                                $_qItem = $_q->requestItem;
                                                if (! $_qItem) continue;
                                                $_slotLabel = $_q->target_slot_key
                                                    ? (function () use ($_q, $_qItem, $slotResolver) {
                                                        $sks = $slotResolver->resolve($_qItem);
                                                        return collect($sks)->firstWhere('key', $_q->target_slot_key)['label']
                                                            ?? \Illuminate\Support\Str::limit($_q->question, 25);
                                                    })()
                                                    : \Illuminate\Support\Str::limit(mb_strtolower($_q->question), 25);
                                                $_chips[] = ['pos' => $_qItem->position, 'label' => mb_strtolower($_slotLabel)];
                                            }
                                            // Сводка вопросов (после «спросил по позициям:»)
                                            $_summary = collect($_chips)->pluck('label')->unique()->implode(', ');

                                            // Дата для «.when»
                                            $_when = $_isSent
                                                ? ($_b->sent_at?->format('d.m H:i') ?: '—')
                                                : ($_b->created_at?->format('d.m H:i') ?: '—');
                                        @endphp

                                        {{-- Question row (always rendered) --}}
                                        <div class="grid items-start gap-3 px-[18px] py-3 border-t border-border-subtle"
                                             style="grid-template-columns: 18px 1fr 130px; font-size:12.5px">
                                            <span class="w-[11px] h-[11px] rounded-full mt-1 shrink-0"
                                                  style="background: var(--sky-500, #0ea5e9)"></span>
                                            <div class="text-fg-1" style="line-height:1.5">
                                                @if($loop->index === 0)
                                                    <b class="font-semibold">{{ $_managerName }}.</b> спросил по позициям: {{ $_summary ?: '—' }}
                                                @else
                                                    <b class="font-semibold">{{ $_managerName }}.</b> повторил: {{ $_summary ?: '—' }}
                                                @endif
                                                @if(! empty($_chips))
                                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                        @foreach($_chips as $_ch)
                                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-surface border border-border text-fg-2 font-medium"
                                                                  style="font-size:11px;line-height:1.3">
                                                                #{{ $_ch['pos'] }} {{ $_ch['label'] }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                            <span class="text-right text-fg-3 mono" style="font-size:11px;line-height:1.4">
                                                {{ $_when }}
                                            </span>
                                        </div>

                                        {{-- Answer row (only if there's at least one client answer in this batch) --}}
                                        @php
                                            $_answeredQs = $_batchQs->filter(fn ($q) => $_clarFilter($q->answer));
                                        @endphp
                                        @if($_answeredQs->isNotEmpty())
                                            @php
                                                $_answerLines = $_answeredQs->map(fn ($q) => trim((string) $q->answer))->filter()->unique()->implode("\n");
                                                $_answerAt = $_answeredQs->map(fn ($q) => $q->answered_at)->filter()->max();
                                            @endphp
                                            <div class="grid items-start gap-3 px-[18px] py-3 border-t border-border-subtle"
                                                 style="grid-template-columns: 18px 1fr 130px; font-size:12.5px">
                                                <span class="w-[11px] h-[11px] rounded-full mt-1 shrink-0"
                                                      style="background: var(--emerald-600, #059669)"></span>
                                                <div class="text-fg-1" style="line-height:1.5">
                                                    <b class="font-semibold">Клиент ответил:</b>
                                                    <div class="mt-1.5 px-3 py-2 bg-surface-2 italic text-fg-2 whitespace-pre-line"
                                                         style="border-left: 2px solid var(--emerald-600, #059669); border-radius:0 4px 4px 0; line-height:1.5">«{{ $_answerLines }}»</div>

                                                    {{-- Matched chips per question --}}
                                                    @php
                                                        $_matched = [];
                                                        foreach ($_answeredQs as $_q) {
                                                            $_qItem = $_q->requestItem;
                                                            if (! $_qItem) continue;
                                                            $_slotLabel = $_q->target_slot_key
                                                                ? (function () use ($_q, $_qItem, $slotResolver) {
                                                                    $sks = $slotResolver->resolve($_qItem);
                                                                    return collect($sks)->firstWhere('key', $_q->target_slot_key)['label']
                                                                        ?? \Illuminate\Support\Str::limit($_q->question, 20);
                                                                })()
                                                                : \Illuminate\Support\Str::limit(mb_strtolower($_q->question), 20);
                                                            $_matched[] = sprintf('#%d %s → %s',
                                                                $_qItem->position,
                                                                mb_strtolower($_slotLabel),
                                                                \Illuminate\Support\Str::limit(trim((string) $_q->answer), 30));
                                                        }
                                                    @endphp
                                                    @if(! empty($_matched))
                                                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                            @foreach($_matched as $_m)
                                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-medium"
                                                                      style="font-size:11px;line-height:1.3;border:1px solid oklch(86% 0.08 160)">✓ {{ $_m }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                                <span class="text-right text-fg-3 mono" style="font-size:11px;line-height:1.4">
                                                    {{ $_answerAt?->format('d.m H:i') ?? '—' }} <span class="text-emerald-600 font-semibold">✓</span>
                                                </span>
                                            </div>
                                        @endif
                                    @endforeach
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
                                @if($canEditItems && ! auth()->user()?->hasRole('secretary'))
                                    <livewire:requests.items.add-item-form
                                        :request-id="$req->id"
                                        :key="'add-item-form-' . $req->id" />
                                @else
                                    <span class="text-fg-3">+ добавить позицию</span>
                                @endif
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
                                        @php $linkedBadge = $linkedReq->displayedStatusBadge; @endphp
                                        <span class="chip {{ $linkedBadge['chipClass'] }} text-[10.5px]"
                                              @if($linkedBadge['label'] !== $linkedReq->status->label())
                                                  title="Operational: {{ $linkedReq->status->label() }}"
                                              @endif>
                                            <span class="dot"></span>{{ $linkedBadge['icon'] ? $linkedBadge['icon'].' ' : '' }}{{ $linkedBadge['label'] }}
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
                         Single-instance, слушают $dispatch события от строк.
                         lazy — те же резоны что у других диалогов (Phase perf).
                    --}}
                    @if($canEditItems)
                        <livewire:requests.items.item-edit-dialog
                            :request-id="$req->id"
                            wire:key="item-edit-{{ $req->id }}" lazy />
                        <livewire:requests.items.item-catalog-link-dialog
                            :request-id="$req->id"
                            wire:key="item-catalog-link-{{ $req->id }}" lazy />
                        <livewire:requests.items.item-photo-rebind-dialog
                            :request-id="$req->id"
                            wire:key="item-photo-rebind-{{ $req->id }}" lazy />
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

            {{-- ───── СЧЕТА ─────
                 Phase 4: список Invoice'ов заявки с inline-actions
                 «✓ Оплачен» / «✕ Аннулировать». Перенесено из action-panel
                 в отдельный таб — там распухало sidebar. --}}
            @case('invoices')
                @php $invList = $this->invoicesForRequest; @endphp
                @if($invList->isEmpty())
                    <div class="ds-card p-8 text-center text-fg-3">
                        <div class="text-fg-1 font-medium mb-1">Счетов ещё нет</div>
                        <div class="text-sm">Когда менеджер выставит счёт через action-panel, он появится здесь.</div>
                        @if($canManage && in_array($req->status, [$RS::Quoted, $RS::AwaitingInvoice, $RS::UnderReview, $RS::InProgress, $RS::AwaitingClientClarification], true))
                            <button type="button"
                                    wire:click="$dispatch('open-issue-invoice-dialog')"
                                    class="btn btn-primary mt-3">
                                📋 Выставить счёт
                            </button>
                        @endif
                    </div>
                @else
                    <div class="ds-card">
                        <div class="ds-card-header">
                            <h3>Счета заявки</h3>
                            <span class="text-[12px] text-fg-3 ml-2">{{ $invList->count() }} {{ $invList->count() === 1 ? 'счёт' : 'счетов' }}</span>
                            <span class="flex-1"></span>
                            <a href="{{ route('invoices.index') }}?q={{ $req->internal_code }}"
                               wire:navigate
                               class="text-[12px] text-[var(--sky-700)] hover:underline">
                                Полный реестр →
                            </a>
                            @if($canManage && in_array($req->status, [$RS::Quoted, $RS::AwaitingInvoice, $RS::UnderReview, $RS::InProgress, $RS::AwaitingClientClarification], true))
                                <button type="button"
                                        wire:click="$dispatch('open-issue-invoice-dialog')"
                                        class="btn btn-sm btn-primary ml-2">
                                    + Выставить
                                </button>
                            @endif
                        </div>

                        <div class="overflow-hidden">
                        <table class="w-full text-[12.5px] table-fixed">
                            <colgroup>
                                <col style="width: 160px">  {{-- № счёта --}}
                                <col style="width: 110px">  {{-- Выставлен --}}
                                <col style="width: 140px">  {{-- Действителен до --}}
                                <col style="width: 130px">  {{-- Сумма --}}
                                <col style="width: 130px">  {{-- Статус --}}
                                <col>                        {{-- Комментарий --}}
                                <col style="width: 210px">  {{-- Actions --}}
                            </colgroup>
                            <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                                <tr>
                                    <th class="px-3 py-2 text-left">№ счёта</th>
                                    <th class="px-3 py-2 text-left">Выставлен</th>
                                    <th class="px-3 py-2 text-left">Действителен до</th>
                                    <th class="px-3 py-2 text-right">Сумма</th>
                                    <th class="px-3 py-2 text-left">Статус</th>
                                    <th class="px-3 py-2 text-left">Комментарий</th>
                                    <th class="px-3 py-2 text-right">Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invList as $inv)
                                    @php
                                        $invStatus = $inv->status?->value ?? 'pending';
                                        $isPending = $invStatus === 'pending';
                                        $isOverdue = $isPending && $inv->expires_at?->isPast();
                                        $chipMap = ['pending'=>'chip-warn','paid'=>'chip-ok','expired'=>'chip-danger','cancelled'=>'chip-paused'];
                                        $labelMap = ['pending'=>'Ожидает','paid'=>'Оплачен','expired'=>'Просрочен','cancelled'=>'Аннулирован'];
                                        $daysRemain = ($isPending && $inv->expires_at) ? now()->diffInDays($inv->expires_at, false) : null;
                                    @endphp
                                    <tr wire:key="inv-tab-{{ $inv->id }}"
                                        class="border-b border-border-subtle last:border-b-0 hover:bg-hover {{ $isOverdue ? 'bg-red-50' : '' }}">
                                        <td class="px-3 py-2 mono text-[12px] text-fg-1 align-top" style="max-width: 0">
                                            <span class="truncate block" title="{{ $inv->invoice_number }}">{{ $inv->invoice_number }}</span>
                                        </td>
                                        <td class="px-3 py-2 mono text-[11.5px] text-fg-2 align-top whitespace-nowrap">
                                            {{ $inv->issued_at?->format('d.m.Y') ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <div class="mono text-[11.5px] text-fg-2 whitespace-nowrap">
                                                {{ $inv->expires_at?->format('d.m.Y') ?? '—' }}
                                            </div>
                                            @if($isPending && $daysRemain !== null)
                                                @if($isOverdue)
                                                    <div class="text-[10.5px] text-red-700 font-medium">⚠ просрочен {{ abs((int) $daysRemain) }} дн.</div>
                                                @elseif($daysRemain <= 2)
                                                    <div class="text-[10.5px] text-amber-700">⏳ осталось {{ (int) $daysRemain }} дн.</div>
                                                @else
                                                    <div class="text-[10.5px] text-fg-3">⏳ {{ (int) $daysRemain }} дн.</div>
                                                @endif
                                            @elseif($invStatus === 'paid' && $inv->paid_at)
                                                <div class="text-[10.5px] text-emerald-700 mono">оплачен {{ $inv->paid_at->format('d.m.Y') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 mono text-[12px] text-fg-1 align-top text-right whitespace-nowrap">
                                            @if($inv->amount_snapshot !== null)
                                                {{ number_format((float) $inv->amount_snapshot, 2, '.', ' ') }} ₽
                                            @else
                                                <span class="text-fg-4">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <span class="chip {{ $chipMap[$invStatus] ?? 'chip-neutral' }}">
                                                <span class="dot"></span>{{ $labelMap[$invStatus] ?? $invStatus }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-[11.5px] text-fg-2 align-top" style="max-width: 0">
                                            @if($inv->comment)
                                                <div class="truncate" title="{{ $inv->comment }}">{{ $inv->comment }}</div>
                                            @elseif($inv->cancellation_reason)
                                                <div class="truncate text-red-700" title="{{ $inv->cancellation_reason }}">отм.: {{ $inv->cancellation_reason }}</div>
                                            @else
                                                <span class="text-fg-4">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right align-top whitespace-nowrap">
                                            @if($isPending && $canManage)
                                                <button type="button"
                                                        wire:click="markInvoicePaid({{ $inv->id }})"
                                                        wire:confirm="Пометить счёт №{{ $inv->invoice_number }} как оплаченный?"
                                                        class="btn btn-sm btn-primary">✓ Оплачен</button>
                                                <button type="button"
                                                        onclick="const r = prompt('Причина аннулирования счёта №{{ $inv->invoice_number }}?'); if (r) @this.call('cancelInvoice', {{ $inv->id }}, r);"
                                                        class="btn btn-sm text-red-700"
                                                        title="Аннулировать счёт">✕</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    </div>
                @endif
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
                    // Phase 1.10 + расширения: state_changes + assignments + ВСЕ
                    // письма треда + новые типы событий (merge, suggestion,
                    // items_parsed_from_reply) в один timeline.
                    $timeline = collect();

                    foreach ($req->stateChanges as $sc) {
                        $fromEnum = $sc->fromStatusEnum();
                        $toEnum = $sc->toStatusEnum();
                        $payload = is_array($sc->payload) ? $sc->payload : [];

                        // Заголовок и kind зависят от event'а: новые semantic-events
                        // имеют свой текст, статусные — стандартный «из → в».
                        switch ($sc->event) {
                            case 'merge_from':
                                $kind = 'state-merge-from';
                                $title = sprintf('Объединена заявка %s', $payload['merged_from_internal_code'] ?? '—');
                                break;
                            case 'merged_into':
                                $kind = 'state-merge-into';
                                $title = sprintf('Заявка объединена с %s', $payload['merged_into_internal_code'] ?? '—');
                                break;
                            case 'items_parsed_from_reply':
                                $kind = 'state-items-from-reply';
                                $active = (int) ($payload['items_added_active'] ?? 0);
                                $pending = (int) ($payload['items_added_pending'] ?? 0);
                                $skipped = (int) ($payload['items_skipped_low_confidence'] ?? 0);
                                $title = sprintf('Парсер из reply: +%d активных, %d предложений, %d пропущено', $active, $pending, $skipped);
                                break;
                            case 'suggestion_applied':
                                $kind = 'state-suggestion-apply';
                                $title = 'Подтверждена pending-позиция от парсера';
                                break;
                            case 'suggestion_rejected':
                                $kind = 'state-suggestion-reject';
                                $title = 'Отклонена pending-позиция от парсера';
                                break;
                            case 'auto_resume_pause':
                                $kind = 'state-auto';
                                $title = $fromEnum
                                    ? sprintf('Статус: «%s» → «%s»', $fromEnum->label(), $toEnum?->label() ?? $sc->to_status)
                                    : ($toEnum?->label() ?? $sc->to_status);
                                break;
                            case 'reanimate':
                                $kind = 'state-reanimate';
                                $title = sprintf('Реанимация: «%s» → «%s»', $fromEnum?->label() ?? '—', $toEnum?->label() ?? $sc->to_status);
                                break;
                            default:
                                $kind = 'state';
                                $title = $fromEnum
                                    ? sprintf('Статус: «%s» → «%s»', $fromEnum->label(), $toEnum?->label() ?? $sc->to_status)
                                    : sprintf('Заявка создана со статусом «%s»', $toEnum?->label() ?? $sc->to_status);
                        }

                        $by = $sc->byUser?->name ?? match ($sc->event) {
                            'auto_resume_pause' => 'cron · авто-возврат с паузы',
                            'reanimate' => 'InboundReplyLinker · автоматически',
                            'items_parsed_from_reply' => 'парсер · автоматически',
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

                    // ВСЕ письма треда — inbound + outbound (раньше показывался
                    // только initial $email).
                    foreach ($thread as $m) {
                        $isIn = $m->direction?->value === 'inbound';
                        $titlePrefix = $isIn ? 'Получено письмо от' : 'Отправлено письмо';
                        $person = $isIn
                            ? ($m->from_name ?: $m->from_email)
                            : (collect((array) $m->to_recipients)->first()['email'] ?? '');
                        $attCount = $m->attachments?->count() ?? 0;
                        $timeline->push([
                            'at' => $m->sent_at,
                            'kind' => $isIn ? 'email-in' : 'email-out',
                            'title' => trim($titlePrefix . ' ' . $person),
                            'by' => null,
                            'details' => $m->subject
                                . ($attCount > 0 ? ' · ' . $attCount . ' вложений' : ''),
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
                                        'state-merge-from' => 'bg-sky-700 border-sky-700',
                                        'state-merge-into' => 'bg-red-700 border-red-700',
                                        'state-items-from-reply' => 'bg-amber-700 border-amber-700',
                                        'state-suggestion-apply' => 'bg-emerald-600 border-emerald-600',
                                        'state-suggestion-reject' => 'bg-red-600 border-red-600',
                                        'assignment' => 'bg-violet-700 border-violet-700',
                                        'email-in' => 'bg-emerald-700 border-emerald-700',
                                        'email-out' => 'bg-indigo-600 border-indigo-600',
                                        'email' => 'bg-emerald-700 border-emerald-700',
                                        default => 'bg-surface border-neutral-400',
                                    };
                                    $iconText = match ($event['kind']) {
                                        'state' => '🔄',
                                        'state-auto' => '⏰',
                                        'state-reanimate' => '↻',
                                        'state-merge-from' => '⊌',
                                        'state-merge-into' => '⊌',
                                        'state-items-from-reply' => '💡',
                                        'state-suggestion-apply' => '✓',
                                        'state-suggestion-reject' => '✕',
                                        'assignment' => '👤',
                                        'email-in' => '✉',
                                        'email-out' => '↗',
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

            {{-- ───── КП ─────
                 Сверху — наш QuotationEditor (исходящие КП, создаваемые менеджером).
                 Ниже — snapshot'ы OutboundQuote: исторически уже отправленные клиенту
                 КП/счета, заполнено ParseOutboundQuoteJob после OutboundDocumentDetector. --}}
            @case('quotes')
                <livewire:requests.quotations.editor :request-id="$req->id" :key="'quot-editor-'.$req->id" />
                @php
                    $quotes = $req->outboundQuotes; // загружены в Detail::mount с items + relations
                    $sourceLabels = [
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_SKU_EXACT => ['M-SKU', 'emerald'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_CATALOG_TO_REQUEST => ['SKU→каталог→заявка', 'emerald'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_SKU_TO_REQUEST => ['SKU→заявка', 'emerald'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_CATALOG_NAME_TO_REQUEST => ['по названию каталога', 'sky'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_FUZZY_ARTICLE => ['fuzzy article', 'sky'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_FUZZY_NAME => ['fuzzy name', 'sky'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_LLM => ['AI', 'amber'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_MANUAL => ['вручную', 'violet'],
                        \App\Models\OutboundQuoteItem::MATCH_SOURCE_UNMATCHED => ['не сматчено', 'neutral'],
                    ];
                    $sourceColors = [
                        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'sky'     => 'bg-sky-50 text-sky-700 border-sky-200',
                        'amber'   => 'bg-amber-50 text-amber-700 border-amber-200',
                        'violet'  => 'bg-violet-50 text-violet-700 border-violet-200',
                        'neutral' => 'bg-neutral-100 text-fg-3 border-border-subtle',
                    ];
                @endphp
                @if($quotes->isNotEmpty())
                    <div class="mt-4 mb-2 text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">
                        Исторические PDF/XLSX отправленные ранее (auto-detected)
                    </div>
                    <div class="space-y-3">
                        @foreach($quotes as $idx => $quote)
                            @php
                                $matched = $quote->items->whereNotNull('matched_request_item_id')->count();
                                $totalLines = $quote->items->count();
                                $matchedPct = $totalLines > 0 ? (int) round($matched / $totalLines * 100) : 0;
                                $isInvoice = $quote->document_type?->value === 'outbound_invoice';
                                $att = $quote->attachment;
                                $hasFile = $att !== null && $att->id !== null;
                                $downloadUrl = $hasFile ? route('attachments.download', $att) : null;
                                // Сумма позиций до общей скидки (если в КП Liftway применил скидку в подвале,
                                // line_total строк не равна total_amount). Показываем расхождение, если есть.
                                $linesSum = (float) $quote->items->sum(fn ($i) => (float) ($i->line_total ?? 0));
                                $hasDiscount = $quote->total_amount !== null
                                    && $linesSum > 0
                                    && abs($linesSum - (float) $quote->total_amount) > 1.0;
                                // Validation warnings от парсера (validateLineTotals).
                                $parseWarnings = data_get($quote->payload, 'warnings', []);
                                $hasWarnings = is_array($parseWarnings) && count($parseWarnings) > 0;
                                $suspectIndexes = collect($parseWarnings)
                                    ->pluck('suspect_item_index')
                                    ->filter(fn ($v) => is_int($v))
                                    ->flip();
                            @endphp
                            <details class="ds-card" {{ $idx === 0 ? 'open' : '' }}>
                                <summary class="ds-card-header cursor-pointer select-none">
                                    <h3>
                                        {{ $isInvoice ? 'Счёт' : 'КП' }}
                                        @if($quote->document_number)
                                            <span class="mono">№{{ $quote->document_number }}</span>
                                        @endif
                                        @if($quote->document_date)
                                            <span class="text-fg-3 font-normal">от {{ $quote->document_date->format('d.m.Y') }}</span>
                                        @endif
                                    </h3>
                                    @if($hasWarnings)
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border bg-amber-50 text-amber-700 border-amber-200 text-[11px]"
                                              title="Парсер обнаружил расхождение арифметики. Раскройте карточку — детали под meta-row.">
                                            ⚠ проверь цифры
                                        </span>
                                    @endif
                                    <span class="flex-1"></span>
                                    <span class="text-fg-1 font-semibold mono">
                                        {{ $quote->total_amount !== null ? number_format((float) $quote->total_amount, 2, '.', ' ') . ' ₽' : '—' }}
                                    </span>
                                    <span class="text-[11.5px] text-fg-3">·</span>
                                    <span class="text-[11.5px] {{ $matched === $totalLines ? 'text-emerald-700' : 'text-fg-3' }}">
                                        сматчено {{ $matched }}/{{ $totalLines }} ({{ $matchedPct }}%)
                                    </span>
                                </summary>
                                <div class="ds-card-body p-0">
                                    {{-- Meta-row: VAT, prices_include_vat, source, download. --}}
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-[18px] py-2.5 border-b border-[var(--border-subtle)] text-[12px]">
                                        @if($quote->vat_amount !== null)
                                            <span class="text-fg-2">
                                                НДС {{ $quote->vat_rate !== null ? rtrim(rtrim(number_format((float) $quote->vat_rate, 2, '.', ' '), '0'), '.') . '%' : '' }}
                                                {{ $quote->prices_include_vat ? '(в т.ч.)' : '(сверху)' }}
                                                <span class="mono ml-0.5">{{ number_format((float) $quote->vat_amount, 2, '.', ' ') }} ₽</span>
                                            </span>
                                        @endif
                                        @if($hasDiscount)
                                            <span class="text-amber-700" title="Сумма построчно (до общей скидки) — {{ number_format($linesSum, 2, '.', ' ') }} ₽; в КП применена общая скидка в подвале.">
                                                подытог: <span class="mono">{{ number_format($linesSum, 2, '.', ' ') }} ₽</span>
                                                <span class="text-fg-3">·</span>
                                                скидка <span class="mono">{{ number_format($linesSum - (float) $quote->total_amount, 2, '.', ' ') }} ₽</span>
                                            </span>
                                        @endif
                                        @php
                                            // Партнёрская скидка от розницы (рассчитывается по строкам). Полезно
                                            // когда в КП Liftway/MyZip действует %-скидка по прайс-листу. Берём
                                            // Σ(base × qty) - Σ(unit_price × qty) только по строкам, где обе цены
                                            // известны, чтобы не путать с подвальной (которая уже учтена в line_total).
                                            $partnerBase = 0.0;
                                            $partnerNet = 0.0;
                                            $partnerCount = 0;
                                            foreach ($quote->items as $pi) {
                                                if ($pi->base_unit_price === null || $pi->unit_price === null || $pi->quantity === null) {
                                                    continue;
                                                }
                                                $qmul = (float) $pi->quantity;
                                                if ($pi->unit_quantity !== null && (float) $pi->unit_quantity > 0) {
                                                    $qmul *= (float) $pi->unit_quantity;
                                                }
                                                if ($qmul <= 0) {
                                                    continue;
                                                }
                                                $partnerBase += (float) $pi->base_unit_price * $qmul;
                                                $partnerNet  += (float) $pi->unit_price * $qmul;
                                                $partnerCount++;
                                            }
                                            $partnerPct = ($partnerBase > 0 && $partnerBase > $partnerNet)
                                                ? round((1 - $partnerNet / $partnerBase) * 100, 1)
                                                : null;
                                        @endphp
                                        @if($partnerCount > 0 && $partnerPct !== null && $partnerPct > 0.1)
                                            <span class="px-1.5 py-0.5 rounded border bg-emerald-50 text-emerald-700 border-emerald-200"
                                                  title="Σ розничных цен по строкам = {{ number_format($partnerBase, 2, '.', ' ') }} ₽; Σ цен со скидкой = {{ number_format($partnerNet, 2, '.', ' ') }} ₽">
                                                партнёрская скидка ≈ −{{ rtrim(rtrim(number_format($partnerPct, 1, '.', ''), '0'), '.') }}%
                                            </span>
                                        @endif
                                        @if($hasFile)
                                            @php
                                                $shownName = \App\Models\OutboundQuote::filenameLooksGarbled($att->filename)
                                                    ? $quote->displayFilename()
                                                    : $att->filename;
                                            @endphp
                                            <span class="flex-1"></span>
                                            <a href="{{ $downloadUrl }}" class="text-sky-700 hover:underline inline-flex items-center gap-1"
                                               title="{{ $att->filename }}">
                                                📎 {{ Str::limit($shownName, 60, '…') }}
                                                <span class="text-fg-3">({{ number_format((int) ($att->size_bytes ?? 0) / 1024, 0, '.', ' ') }} KB)</span>
                                                <span class="text-fg-2">— скачать →</span>
                                            </a>
                                        @endif
                                    </div>

                                    {{-- Phase 7: warnings от парсера. Раскрытый список расхождений
                                         (row arithmetic, Σ items vs total). --}}
                                    @if($hasWarnings)
                                        <div class="px-[18px] py-2.5 border-b border-[var(--border-subtle)] bg-amber-50/40">
                                            <div class="text-[12px] font-semibold text-amber-800 mb-1">⚠ Парсер обнаружил расхождение арифметики</div>
                                            <ul class="text-[11.5px] text-amber-800 space-y-0.5 list-disc pl-4">
                                                @foreach($parseWarnings as $w)
                                                    <li>{{ $w['message'] ?? '—' }}</li>
                                                @endforeach
                                            </ul>
                                            <div class="text-[11px] text-fg-3 mt-1.5">
                                                Возможные причины: Vision галлюцинировал на одной из строк (прибавил подвальную скидку),
                                                либо в PDF действительно есть структура «Σ строк ≠ Итого». Проверь подозрительные строки
                                                в таблице ниже (помечены амбер-фоном) или перепарси с <code>quotes:parse-outbound --apply --reset --quote={{ $quote->id }}</code>.
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Таблица строк КП. --}}
                                    @if($quote->items->isEmpty())
                                        <div class="px-[18px] py-6 text-center text-fg-3 text-sm">Парсер не извлёк ни одной строки.</div>
                                    @else
                                        <table class="w-full text-[12.5px]">
                                            <thead class="text-[10.5px] uppercase tracking-wider text-fg-3 bg-neutral-50">
                                                <tr>
                                                    <th class="text-left px-[18px] py-1.5 font-semibold">#</th>
                                                    <th class="text-left py-1.5 font-semibold">Наименование</th>
                                                    <th class="text-right py-1.5 font-semibold">Кол-во</th>
                                                    <th class="text-right py-1.5 font-semibold">Цена</th>
                                                    <th class="text-right py-1.5 font-semibold">Сумма</th>
                                                    <th class="text-left py-1.5 font-semibold whitespace-nowrap">Срок</th>
                                                    <th class="text-left py-1.5 px-[18px] font-semibold">Заявка</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-[var(--border-subtle)]">
                                                @foreach($quote->items as $qiLoopIdx => $qi)
                                                    @php
                                                        [$srcLabel, $srcColor] = $sourceLabels[$qi->match_source] ?? ['—', 'neutral'];
                                                        $srcCss = $sourceColors[$srcColor];
                                                        $ri = $qi->requestItem;
                                                        $cat = $qi->catalogItem;
                                                        $isSuspect = $suspectIndexes->has($qiLoopIdx);
                                                        $rowClass = $isSuspect
                                                            ? 'bg-amber-100/60'  // суспект арифметики — ярче
                                                            : ($qi->matched_request_item_id === null ? 'bg-amber-50/30' : '');
                                                    @endphp
                                                    <tr class="{{ $rowClass }} align-top" title="{{ $isSuspect ? 'Подозрительная арифметика — см. warnings выше' : '' }}">
                                                        <td class="px-[18px] py-2 text-fg-3 mono">{{ $qi->position }}</td>
                                                        <td class="py-2 pr-3">
                                                            <div class="text-fg-1">{{ $qi->raw_name ?: '—' }}</div>
                                                            <div class="text-[11px] text-fg-3 mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5">
                                                                @if($qi->raw_article)
                                                                    <span class="mono">{{ $qi->raw_article }}</span>
                                                                @endif
                                                                @if($qi->raw_brand)
                                                                    <span>{{ $qi->raw_brand }}</span>
                                                                @endif
                                                                @if($cat)
                                                                    <a href="https://mylift.ru/search?q={{ urlencode($cat->sku) }}" target="_blank" rel="noopener"
                                                                       class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border bg-emerald-50 text-emerald-700 border-emerald-200 hover:underline"
                                                                       title="{{ $cat->name }}">
                                                                        📦 каталог: <span class="mono">{{ $cat->sku }}</span>
                                                                    </a>
                                                                @elseif($qi->matched_catalog_item_id)
                                                                    <span class="text-fg-3">catalog#{{ $qi->matched_catalog_item_id }}</span>
                                                                @endif
                                                                @if($qi->is_analog)
                                                                    <span class="px-1.5 py-0.5 rounded border bg-sky-50 text-sky-700 border-sky-200">аналог</span>
                                                                @endif
                                                            </div>
                                                            @if($qi->notes)
                                                                <div class="text-[11px] text-fg-3 mt-0.5 italic">{{ Str::limit($qi->notes, 120) }}</div>
                                                            @endif
                                                        </td>
                                                        <td class="py-2 text-right text-fg-1 mono whitespace-nowrap">
                                                            {{ $qi->quantity !== null ? rtrim(rtrim(number_format((float) $qi->quantity, 3, '.', ' '), '0'), '.') : '—' }}
                                                            <span class="text-fg-3 text-[10.5px]">{{ $qi->unit_measure ?: '' }}</span>
                                                        </td>
                                                        <td class="py-2 text-right text-fg-1 mono whitespace-nowrap">
                                                            @php
                                                                // Партнёрская скидка: показываем розницу зачёркнутой над финальной
                                                                // ценой и шильдик «-X%». Источник истины — поля документа
                                                                // (`base_unit_price`, `discount_percent`); если документ скидку
                                                                // не разделил, fallback на вычисление по двум ценам.
                                                                $hasDiscount = $qi->hasDiscount();
                                                                $discountPct = $hasDiscount ? $qi->effectiveDiscountPercent() : null;
                                                            @endphp
                                                            @if($hasDiscount && $qi->base_unit_price !== null)
                                                                <div class="text-fg-3 line-through text-[11px]" title="Розничная цена (до скидки)">
                                                                    {{ number_format((float) $qi->base_unit_price, 2, '.', ' ') }}
                                                                </div>
                                                            @endif
                                                            <div class="{{ $hasDiscount ? 'font-semibold' : '' }}">
                                                                {{ $qi->unit_price !== null ? number_format((float) $qi->unit_price, 2, '.', ' ') : '—' }}
                                                            </div>
                                                            @if($hasDiscount && $discountPct !== null)
                                                                <div class="text-[10.5px] text-emerald-700 mt-0.5"
                                                                     title="Скидка от розницы">
                                                                    −{{ rtrim(rtrim(number_format($discountPct, 2, '.', ''), '0'), '.') }}%
                                                                </div>
                                                            @endif
                                                        </td>
                                                        <td class="py-2 text-right text-fg-1 mono font-semibold whitespace-nowrap">
                                                            {{ $qi->line_total !== null ? number_format((float) $qi->line_total, 2, '.', ' ') : '—' }}
                                                        </td>
                                                        <td class="py-2 text-fg-2 whitespace-nowrap">
                                                            @if($qi->delivery_days === null)
                                                                <span class="text-fg-3">—</span>
                                                            @elseif($qi->delivery_days === 0)
                                                                <span class="text-emerald-700">в наличии</span>
                                                            @else
                                                                {{ $qi->delivery_days }} раб. дн.
                                                            @endif
                                                        </td>
                                                        <td class="py-2 px-[18px]">
                                                            @if($ri)
                                                                <a href="#" wire:click.prevent="setTab('items')" class="text-sky-700 hover:underline text-[12px]"
                                                                   title="{{ $ri->parsed_name }}">
                                                                    поз. №{{ $ri->position ?? '?' }}
                                                                </a>
                                                                <div class="text-[10.5px] mt-0.5 flex items-center gap-1.5 flex-wrap">
                                                                    <span class="inline-block px-1.5 py-0.5 rounded border {{ $srcCss }}">{{ $srcLabel }}</span>
                                                                    @if($qi->match_score)
                                                                        <span class="text-fg-3 mono">{{ number_format($qi->match_score * 100, 0) }}%</span>
                                                                    @endif
                                                                    @if($canManage || $canReassign)
                                                                        <button type="button"
                                                                                x-on:click="$dispatch('open-quote-match', { quoteItemId: {{ $qi->id }} })"
                                                                                class="text-sky-700 hover:underline"
                                                                                title="Изменить привязку или отвязать">
                                                                            🔄
                                                                        </button>
                                                                    @endif
                                                                </div>
                                                            @else
                                                                <div class="flex items-center gap-1.5 flex-wrap">
                                                                    <span class="inline-block px-1.5 py-0.5 rounded border {{ $sourceColors['neutral'] }} text-[10.5px]">
                                                                        не сматчено
                                                                    </span>
                                                                    @if($canManage || $canReassign)
                                                                        <button type="button"
                                                                                x-on:click="$dispatch('open-quote-match', { quoteItemId: {{ $qi->id }} })"
                                                                                class="text-[10.5px] text-sky-700 hover:underline">
                                                                            🔗 Привязать
                                                                        </button>
                                                                    @endif
                                                                </div>
                                                                @if($qi->matched_catalog_item_id)
                                                                    <div class="text-[10.5px] text-fg-3 mt-0.5">в каталоге есть, в заявке нет</div>
                                                                @endif
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot>
                                                <tr class="border-t border-[var(--border-subtle)] bg-neutral-50/60">
                                                    <td colspan="4" class="px-[18px] py-2 text-fg-3 text-[11.5px]">
                                                        Источник: {{ $quote->source === 'attachment' ? '📎 вложение' : 'тело письма' }}
                                                        · парсер: {{ $quote->parsed_at?->format('d.m.Y H:i') ?? '—' }}
                                                    </td>
                                                    <td class="py-2 text-right text-fg-1 font-semibold mono whitespace-nowrap" colspan="3">
                                                        Итого по КП: {{ $quote->total_amount !== null ? number_format((float) $quote->total_amount, 2, '.', ' ') . ' ₽' : '—' }}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    @endif
                                </div>
                            </details>
                        @endforeach
                    </div>
                @endif
                @break

            {{-- ───── ФАЙЛЫ ───── --}}
            @case('files')
                @php
                    // Все вложения треда: trigger-email + reply'и.
                    $threadAtts = $thread->flatMap(fn ($m) => $m->attachments);
                    // Phase 7: карта attachment_id → OutboundQuote (для chip «📨 КП №NNN»).
                    // Берём из уже eager-loaded outboundQuotes (matched), один attachment
                    // может быть source только для одного OutboundQuote (UNIQUE constraint).
                    $quoteByAttachment = $req->outboundQuotes
                        ->filter(fn ($q) => $q->email_attachment_id !== null)
                        ->keyBy('email_attachment_id');

                    // Phase 7: если PDF КП пришёл через письмо НЕ привязанное к этой
                    // заявке (related_request_id != req.id — например письмо в Sent с
                    // ослабленной link-цепочкой), его не будет в $thread. Добавляем
                    // такие attachment'ы явно из outboundQuotes->attachment, чтобы они
                    // не пропали в табе «Файлы» (источник критичен для аудита).
                    $threadAttIds = $threadAtts->pluck('id')->all();
                    $extraQuoteAtts = $req->outboundQuotes
                        ->pluck('attachment')
                        ->filter()
                        ->reject(fn ($a) => in_array($a->id, $threadAttIds, true));
                    $allAttachments = $threadAtts->concat($extraQuoteAtts)->unique('id')->values();
                @endphp
                @php
                    // Галерея ВСЕХ image-вложений треда для листания
                    // в лайтбоксе из вкладки «Файлы».
                    $filesGallery = $allAttachments
                        ->filter(fn ($a) => $isImageAttachment($a))
                        ->values()
                        ->map(fn ($a) => [
                            'src' => route('attachments.preview', $a),
                            'name' => $a->filename,
                            'dl' => route('attachments.download', $a),
                        ])
                        ->all();
                    $filesImgIdx = 0;
                @endphp
                <div class="ds-card" x-data="{ items: @js($filesGallery) }">
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
                                    $thisImgIdx = $isImg ? $filesImgIdx : null;
                                    if ($isImg) { $filesImgIdx++; }
                                @endphp
                                <div class="flex items-center gap-3 px-[18px] py-2.5 hover:bg-hover transition-colors">
                                    @if($isImg)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { items: items, index: {{ $thisImgIdx }} })"
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
                                        @php
                                            $attQuote = $quoteByAttachment->get($att->id);
                                            // Если filename выглядит мусором — заменяем на синтезированное
                                            // имя из OutboundQuote (если он привязан). Реальный filename
                                            // остаётся в tooltip и при скачивании.
                                            $shownName = ($attQuote && \App\Models\OutboundQuote::filenameLooksGarbled($att->filename))
                                                ? $attQuote->displayFilename()
                                                : ($att->filename ?: 'Без имени');
                                        @endphp
                                        <div class="text-fg-1 truncate text-sm flex items-center gap-2 flex-wrap">
                                            <span class="truncate" title="{{ $att->filename }}">{{ $shownName }}</span>
                                            @if($attQuote)
                                                @php $isInvoice = $attQuote->document_type === \App\Enums\DetectorType::OutboundInvoice; @endphp
                                                <button type="button" wire:click="setTab('quotes')"
                                                        class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border bg-sky-50 text-sky-700 border-sky-200 hover:bg-sky-100 transition-colors text-[11px] whitespace-nowrap"
                                                        title="{{ $attQuote->total_amount !== null ? number_format((float) $attQuote->total_amount, 2, '.', ' ') . ' ₽ · сматчено ' . $attQuote->matchedCount() . '/' . $attQuote->items->count() : '' }}">
                                                    📨 {{ $isInvoice ? 'Счёт' : 'КП' }}
                                                    @if($attQuote->document_number)
                                                        <span class="mono">№{{ $attQuote->document_number }}</span>
                                                    @endif
                                                </button>
                                            @endif
                                        </div>
                                        <div class="text-[11.5px] text-fg-3 mt-0.5">
                                            {{ $att->mime_type ?: '—' }}
                                            @if($att->size_bytes) · {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB @endif
                                            @if($att->is_inline) · <span class="text-sky-700">inline</span> @endif
                                        </div>
                                    </div>
                                    @if($isImg)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { items: items, index: {{ $thisImgIdx }} })"
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
         Открывается событием window:open-image с detail:
           - legacy: {src, name, dl} — одиночная картинка;
           - gallery: {items: [{src,name,dl},...], index: N} — листаемая.
         Закрытие: Esc, клик по бэкдропу, кнопка «Закрыть».
         Навигация: ← / → (клавиатура и кнопки), wrap-around.
         Inline-стили для критичной геометрии. --}}
    <div x-data="{
            lbOpen: false,
            lbItems: [],
            lbIdx: 0,
            lbSrc: '',
            lbName: '',
            lbDl: '',
            sync() {
                const it = this.lbItems[this.lbIdx] || { src: '', name: '', dl: '' };
                this.lbSrc = it.src || '';
                this.lbName = it.name || '';
                this.lbDl = it.dl || '';
            },
            open(detail) {
                if (detail && Array.isArray(detail.items) && detail.items.length > 0) {
                    this.lbItems = detail.items.slice();
                    this.lbIdx = Math.max(0, Math.min(parseInt(detail.index) || 0, this.lbItems.length - 1));
                } else if (detail) {
                    this.lbItems = [{ src: detail.src, name: detail.name, dl: detail.dl }];
                    this.lbIdx = 0;
                } else {
                    return;
                }
                this.sync();
                this.lbOpen = true;
            },
            prev() {
                if (this.lbItems.length > 1) {
                    this.lbIdx = (this.lbIdx - 1 + this.lbItems.length) % this.lbItems.length;
                    this.sync();
                }
            },
            next() {
                if (this.lbItems.length > 1) {
                    this.lbIdx = (this.lbIdx + 1) % this.lbItems.length;
                    this.sync();
                }
            },
         }"
         x-on:open-image.window="open($event.detail)"
         x-on:keydown.escape.window="lbOpen = false"
         x-on:keydown.left.window="if (lbOpen) prev()"
         x-on:keydown.right.window="if (lbOpen) next()">
        <div x-show="lbOpen"
             x-transition.opacity.duration.150ms
             style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.82); cursor: zoom-out;"
             x-on:click.self="lbOpen = false">
            <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; align-items: center; gap: 8px; z-index: 2;">
                <span style="color: rgba(255,255,255,0.92); font-size: 12px; font-family: var(--font-mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <span x-text="lbName"></span>
                    <span x-show="lbItems.length > 1" style="opacity: 0.7;"> · <span x-text="lbIdx + 1"></span> / <span x-text="lbItems.length"></span></span>
                </span>
                <a :href="lbDl" download class="btn btn-sm" x-on:click.stop>Скачать</a>
                <button type="button" class="btn btn-sm" x-on:click.stop="lbOpen = false">Закрыть</button>
            </div>
            {{-- Prev / Next кнопки. Скрыты если в галерее всего 1 картинка. --}}
            <button type="button"
                    x-show="lbItems.length > 1"
                    x-on:click.stop="prev()"
                    title="Предыдущее (←)"
                    style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); z-index: 2; width: 44px; height: 44px; border-radius: 50%; border: none; background: rgba(255,255,255,0.12); color: white; font-size: 22px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                ‹
            </button>
            <button type="button"
                    x-show="lbItems.length > 1"
                    x-on:click.stop="next()"
                    title="Следующее (→)"
                    style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); z-index: 2; width: 44px; height: 44px; border-radius: 50%; border: none; background: rgba(255,255,255,0.12); color: white; font-size: 22px; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                ›
            </button>
            <img :src="lbSrc" :alt="lbName"
                 style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: calc(100vw - 120px); max-height: calc(100vh - 80px); width: auto; height: auto; object-fit: contain; cursor: default; display: block; box-shadow: 0 8px 32px rgba(0,0,0,0.5);"
                 x-on:click.stop>
        </div>
    </div>
</div>
