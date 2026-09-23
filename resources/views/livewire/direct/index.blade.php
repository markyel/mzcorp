@php
    $inp = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
@endphp

<div class="space-y-4">

    @if($notice)
        <div class="ds-card"><div class="ds-card-body flex items-center gap-2 text-[13px] text-emerald-700">
            <span>{{ $notice }}</span><span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="dismiss">Скрыть</button>
        </div></div>
    @endif
    @if($error)
        <div class="ds-card"><div class="ds-card-body flex items-center gap-2 text-[13px] text-amber-800">
            <span>{{ $error }}</span><span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="dismiss">Скрыть</button>
        </div></div>
    @endif

    {{-- Связь с Директом --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🔌 Связь с Яндекс.Директом</h3>
            <span class="chip text-[10.5px]"
                  style="background:{{ $hasToken ? 'var(--emerald-50)' : 'var(--red-50)' }};color:{{ $hasToken ? 'var(--emerald-700)' : 'var(--red-700)' }}">
                <span class="dot"></span>{{ $hasToken ? 'токен есть' : 'токена нет' }}
            </span>
            @if($sandbox)
                <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">режим песочницы</span>
            @else
                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">боевой контур</span>
            @endif
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                <span wire:loading.remove wire:target="checkConnection">Проверить связь</span>
                <span wire:loading wire:target="checkConnection">Проверяю…</span>
            </button>
        </div>
        <div class="ds-card-body space-y-2">
            <div class="text-[12px] text-fg-3">
                Эндпоинт: <span class="mono text-fg-2">{{ $endpoint }}</span>
                @unless($feedToken)
                    · <span class="text-amber-700">фид не настроен: пуст YANDEX_DIRECT_FEED_TOKEN</span>
                @endunless
            </div>

            @if($check)
                @if($check['units'])
                    <div class="text-[12.5px] text-fg-2">
                        Баллы API: потрачено на запрос <b class="mono">{{ $check['units']['spent'] }}</b>,
                        осталось на сутки <b class="mono">{{ $money($check['units']['rest']) }}</b>
                        из <span class="mono">{{ $money($check['units']['limit']) }}</span>
                        <span class="text-fg-4">· проверено в {{ $check['at'] }}</span>
                    </div>
                @endif
                <div>
                    <div class="text-[12px] text-fg-3 mb-1">Кампании аккаунта: {{ count($check['campaigns']) }}</div>
                    @foreach($check['campaigns'] as $c)
                        <div class="flex items-center gap-2 text-[12.5px] py-1 border-t border-border-subtle">
                            <span class="mono text-fg-4">#{{ $c['id'] }}</span>
                            <span class="text-fg-1">{{ $c['name'] }}</span>
                            <span class="chip text-[10.5px]"
                                  style="background:{{ $c['state'] === 'ON' ? 'var(--emerald-50)' : 'var(--neutral-100)' }};color:{{ $c['state'] === 'ON' ? 'var(--emerald-700)' : 'var(--fg-3)' }}">
                                {{ $c['state'] === 'ON' ? 'идёт показ' : 'выключена' }}
                            </span>
                            <span class="text-[11.5px] text-fg-4">{{ $c['status'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-[12.5px] text-fg-3">Нажмите «Проверить связь» — запрос покажет остаток баллов и кампании аккаунта.</div>
            @endif
        </div>
    </div>

    {{-- Сколько объявлений держим --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🎚 Сколько объявлений держим</h3>
            <span class="text-[12px] text-fg-3">верхние позиции очереди уходят в Директ, остальные ждут</span>
        </div>
        <div class="ds-card-body">
            <form wire:submit.prevent="saveLimit" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Показываем одновременно</label>
                    <input type="number" wire:model="adsLimit" min="{{ \App\Livewire\Direct\Index::MIN_ADS_LIMIT }}"
                           max="{{ \App\Livewire\Direct\Index::MAX_ADS_LIMIT }}" class="{{ $inp }} w-[120px] mono">
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Держим готовыми</label>
                    <input type="number" wire:model="benchSize" min="{{ \App\Livewire\Direct\Index::MIN_ADS_LIMIT }}"
                           max="{{ \App\Services\Direct\DirectSyncService::MAX_BENCH }}" class="{{ $inp }} w-[120px] mono">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                <span class="flex-1"></span>
                <div class="text-[12.5px] text-fg-2">
                    Годных к показу позиций: <b class="mono">{{ $money($this->readyCount) }}</b>
                    <span class="text-fg-4">— остаток на складе и актуальная цена</span>
                </div>
            </form>
            <div class="mt-2 text-[11.5px] text-fg-4">
                Рекламируем только то, на что можем сразу дать цену: по такой заявке с артикулом
                КП уходит автоматически. Начинаем с малого числа и расширяемся по мере окупаемости.
                Разница между «показываем» и «держим готовыми» — скамейка запасных: объявления
                написаны, созданы и прошли модерацию, но выключены. Выпала позиция из наличия —
                на её место мгновенно встаёт готовое, а не идёт весь путь с нуля.
            </div>
        </div>
    </div>

    {{-- Тон рекламных текстов --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🎭 Тон объявлений</h3>
            <span class="text-[12px] text-fg-3">как написано — факты берутся из карточки при любом тоне</span>
            <span class="flex-1"></span>
            <span class="text-[12px] text-fg-3">сейчас: <b class="text-fg-1">{{ \App\Services\Direct\DirectAdTone::label($adTone) }}</b></span>
        </div>
        <div class="ds-card-body">
            <div class="flex flex-wrap gap-2">
                @foreach($tones as $key => $tone)
                    <button type="button" wire:key="tone-{{ $key }}" wire:click="$set('adTone', '{{ $key }}')"
                            class="text-left px-3 py-2 rounded-md border transition-colors"
                            style="border-color:{{ $adTone === $key ? 'var(--sky-500)' : 'var(--border)' }};
                                   background:{{ $adTone === $key ? 'var(--sky-50)' : 'transparent' }};max-width:260px">
                        <div class="text-[12.5px] font-medium text-fg-1">{{ $tone['label'] }}</div>
                        <div class="text-[11px] text-fg-3 leading-snug mt-0.5">{{ $tone['hint'] }}</div>
                    </button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3 mt-3">
                <button type="button" class="btn btn-sm btn-primary" wire:click="saveTone">Сохранить тон</button>
                <span class="text-[11.5px] text-fg-4">
                    Тон применяется к новым текстам. Уже написанные объявления не меняются сами:
                    каждая правка текста у работающего объявления — это повторная модерация.
                </span>
            </div>
        </div>
    </div>

    {{-- Позиции: очередь, тексты и публикация одним списком --}}
    @php
        $plan = $this->plan;
        $ads = $this->publishedAds->keyBy('sku');
        $campaign = $this->campaign;
        $ops = $this->operations;

        $warned = $plan->filter(fn ($p) => $p['warnings'] !== [])->count();
        $pending = $plan->filter(fn ($p) => $p['source'] === 'rule')->count();
        $left = $plan->filter(fn ($p) => $p['keywords'] !== [] && ! ($ads[$p['sku']] ?? null)?->isComplete())->count();
        $drafts = $ads->filter(fn ($a) => $a->isDraft())->count();

        $fields = [
            'title' => ['Заголовок', \App\Services\Direct\DirectAdPlanService::TITLE_MAX],
            'title2' => ['Второй заголовок', \App\Services\Direct\DirectAdPlanService::TITLE2_MAX],
            'text' => ['Текст', \App\Services\Direct\DirectAdPlanService::TEXT_MAX],
        ];
        $srcStyle = fn ($s) => $s === 'manual'
            ? 'background:var(--emerald-50);color:var(--emerald-700)'
            : ($s === 'ai' ? 'background:var(--sky-50);color:var(--sky-700)' : 'background:var(--neutral-100);color:var(--fg-3)');

        // Одна строка — одно состояние позиции, от «в очереди» до «идут показы».
        $stage = function ($p) use ($ads) {
            $ad = $ads[$p['sku']] ?? null;
            if ($ad === null || $ad->ad_id === null) {
                return $p['keywords'] === []
                    ? ['нечем рекламировать', 'background:var(--red-50);color:var(--red-700)']
                    : ['не опубликована', 'background:var(--neutral-100);color:var(--fg-3)'];
            }

            return match (true) {
                $ad->state === 'ARCHIVED' => ['в архиве', 'background:var(--neutral-100);color:var(--fg-3)'],
                $ad->isRejected() => ['отклонено', 'background:var(--red-50);color:var(--red-700)'],
                $ad->status === 'DRAFT' => ['черновик', 'background:var(--neutral-100);color:var(--fg-3)'],
                $ad->status === 'MODERATION' => ['на модерации', 'background:var(--amber-50);color:var(--amber-800)'],
                $ad->state === 'ON' => ['идут показы', 'background:var(--emerald-50);color:var(--emerald-700)'],
                $ad->state === 'SUSPENDED' => ['в резерве', 'background:var(--sky-50);color:var(--sky-700)'],
                default => ['готово, ждёт кампании', 'background:var(--sky-50);color:var(--sky-700)'],
            };
        };

        // Кто в показе, решает не порядок очереди, а факт: место более денежной
        // позиции, пока она на модерации, занимает следующая готовая. Поэтому
        // делим список по состоянию в Директе — иначе строка «идут показы»
        // оказывается под чертой «в показ не уходят».
        $onAir = fn ($p) => ($ads[$p['sku']] ?? null)?->state === 'ON';
        $plan = $plan->sortByDesc($onAir)->values();
    @endphp

    {{-- Что приносит показы: наши фразы или подбор Яндекса --}}
    @php $st = $this->stats; @endphp
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📈 Что приносит показы</h3>
            <span class="text-[12px] text-fg-3">за {{ $st['days'] }} дн.</span>
        </div>
        <div class="ds-card-body">
            @if($st['impressions'] === 0)
                <p class="text-[12.5px] text-fg-3">
                    Показов за период нет — либо их правда не было, либо отчёт Директа ещё не догнал
                    живой счётчик: разрезы отстают на несколько часов, данные тянутся каждый час.
                </p>
            @else
                <div class="flex flex-wrap gap-5 text-[12.5px] text-fg-2 mb-3">
                    <span>показов: <b class="mono text-fg-1">{{ $st['impressions'] }}</b></span>
                    <span>из них автотаргетинг:
                        <b class="mono text-fg-1">{{ $st['auto']['impressions'] }}</b></span>
                    <span>кликов: <b class="mono text-fg-1">{{ $st['clicks'] }}</b></span>
                    <span>расход: <b class="mono text-fg-1">{{ number_format($st['cost'], 2, ',', ' ') }} ₽</b></span>
                </div>

                {{-- Аккаунт целиком: мусорный запрос приходит туда, куда его принесло. --}}
                @if($st['campaigns']->isNotEmpty())
                    @php $manageable = app(\App\Services\Direct\DirectNegativeService::class)->manageable(); @endphp
                    <div class="mb-3">
                        <div class="text-[11.5px] text-fg-4 mb-1">Кампании аккаунта</div>
                        @foreach($st['campaigns'] as $c)
                            <div class="flex items-baseline gap-2 text-[12.5px] py-[3px] border-b border-border-subtle">
                                <span class="mono text-[11px] text-fg-4">#{{ $c['id'] }}</span>
                                <span class="flex-1 truncate text-fg-1">{{ $c['name'] }}</span>
                                @if($c['ours'])
                                    <span class="chip text-[10px]" style="background:var(--emerald-50);color:var(--emerald-700)">наша</span>
                                @endif
                                @if(! in_array($c['id'], $manageable, true))
                                    <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)"
                                          title="API Директа такие кампании не отдаёт — только статистика, минус-фразы добавляются в кабинете">только чтение</span>
                                @endif
                                <span class="mono text-fg-2 w-[56px] text-right">{{ $c['impressions'] }}</span>
                                <span class="mono text-fg-4 w-[40px] text-right">{{ $c['clicks'] }}</span>
                                <span class="mono text-fg-4 w-[86px] text-right">{{ number_format($c['cost'], 2, ',', ' ') }} ₽</span>
                            </div>
                        @endforeach
                        <p class="text-[11px] text-fg-4 mt-1">Столбцы: показы, клики, расход.</p>
                    </div>
                @endif

                {{-- Чужие запросы — отдельно и сверху: показов у них единицы, и в
                     общем списке по убыванию показов они уезжают под низ, хотя
                     это единственные строки, с которыми надо что-то делать. --}}
                @if($this->pendingForeign->isNotEmpty())
                    <div class="mb-3 p-2 rounded-md" style="background:var(--red-50)">
                        <div class="flex items-center flex-wrap gap-2 mb-1">
                            <span class="text-[11.5px] text-fg-3 flex-1">
                                Чужие запросы — можно вычесть ({{ $this->pendingForeign->count() }})
                            </span>
                            @if($pickedQueries)
                                <span class="text-[11.5px] text-fg-3">отмечено {{ count($pickedQueries) }}</span>
                                <button type="button" class="btn btn-xs btn-primary" wire:click="excludePicked"
                                        wire:loading.attr="disabled" wire:target="excludePicked">Вычесть отмеченные</button>
                                <button type="button" class="btn btn-xs" wire:click="keepPicked">Оставить отмеченные</button>
                                <button type="button" class="btn btn-xs" wire:click="clearPickedQueries">снять</button>
                            @else
                                <button type="button" class="btn btn-xs" wire:click="pickAllQueries">отметить все</button>
                            @endif
                        </div>
                        @foreach($this->pendingForeign as $rv)
                            <div class="flex items-center gap-2 text-[12.5px] py-[3px]" wire:key="pf-{{ $rv->id }}">
                                <input type="checkbox" value="{{ $rv->id }}" wire:model.live="pickedQueries"
                                       class="shrink-0" title="Отметить для массового действия">
                                <span class="flex-1 truncate text-fg-1" title="{{ $rv->reason }}">{{ $rv->query }}</span>
                                <span class="mono text-[11px] text-fg-4">{{ $rv->impressions }}</span>
                                <button type="button" class="btn btn-xs" wire:click="excludeQuery({{ $rv->id }})"
                                        title="Добавить минус-фразу в кампанию #{{ $rv->campaign_id }}">− {{ $rv->phrase }}</button>
                                <button type="button" class="btn btn-xs" wire:click="keepQuery({{ $rv->id }})"
                                        title="Модель ошиблась, запрос наш">оставить</button>
                            </div>
                        @endforeach
                        <p class="text-[11px] text-fg-4 mt-1">
                            Минус-фразы уходят одной правкой на кампанию. Перед «вычесть все» стоит
                            пробежать список глазами: модель ошибается в обе стороны.
                        </p>
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <div class="text-[11.5px] text-fg-4 mb-1">Фразы кампаний аккаунта</div>
                        @forelse($st['phrases'] as $row)
                            <div class="flex items-baseline gap-2 text-[12.5px] py-[3px] border-b border-border-subtle">
                                <span class="flex-1 truncate text-fg-1">{{ $row['name'] }}</span>
                                @if($row['sku'])<span class="mono text-[11px] text-fg-4">{{ $row['sku'] }}</span>@endif
                                {{-- Статистика теперь по всему аккаунту: без номера непонятно,
                                     чья это фраза — наша или соседней кампании. --}}
                                @if($row['campaign_id'])
                                    <span class="mono text-[10.5px] text-fg-4">#{{ substr((string) $row['campaign_id'], -4) }}</span>
                                @endif
                                <span class="mono text-fg-2">{{ $row['impressions'] }}</span>
                                <span class="mono text-fg-4 w-[30px] text-right">{{ $row['clicks'] }}</span>
                            </div>
                        @empty
                            <p class="text-[12px] text-fg-4">Ни одна наша фраза показов пока не дала.</p>
                        @endforelse
                    </div>
                    <div>
                        @php $reviews = $this->reviews; @endphp
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[11.5px] text-fg-4 flex-1">
                                Что люди искали на самом деле — и чей это запрос
                            </span>
                            @php $auto = app(\App\Services\Direct\DirectNegativeService::class)->autoEnabled(); @endphp
                            <button type="button" class="btn btn-xs" wire:click="toggleNegativesAuto"
                                    title="{{ $auto
                                        ? 'Сейчас уверенно чужие запросы вычитаются сами'
                                        : 'Сейчас каждая минус-фраза ждёт вашей кнопки' }}">
                                {{ $auto ? 'авто: вкл' : 'авто: выкл' }}
                            </button>
                            <button type="button" class="btn btn-xs" wire:click="judgeQueries"
                                    wire:loading.attr="disabled" wire:target="judgeQueries">Разобрать новые</button>
                        </div>
                        @forelse($st['queries'] as $row)
                            @php $rv = $reviews[mb_strtolower($row['name'])] ?? null; @endphp
                            <div class="flex items-baseline gap-2 text-[12.5px] py-[3px] border-b border-border-subtle"
                                 wire:key="q-{{ md5($row['name']) }}">
                                <span class="flex-1 truncate text-fg-1" title="{{ $rv?->reason }}">{{ $row['name'] }}</span>

                                @if($rv?->decision === \App\Models\DirectQueryReview::EXCLUDED)
                                    <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">исключён</span>
                                @elseif($rv?->decision === \App\Models\DirectQueryReview::KEPT)
                                    <span class="chip text-[10px]" style="background:var(--emerald-50);color:var(--emerald-700)">наш</span>
                                @elseif($rv?->verdict === \App\Models\DirectQueryReview::FOREIGN)
                                    <span class="chip text-[10px]" style="background:var(--red-50);color:var(--red-700)"
                                          title="{{ $rv->reason }}">чужой</span>
                                    <button type="button" class="btn btn-xs" wire:click="excludeQuery({{ $rv->id }})"
                                            title="Добавить минус-фразу «{{ $rv->phrase }}» в кампанию">− {{ $rv->phrase }}</button>
                                    <button type="button" class="btn btn-xs" wire:click="keepQuery({{ $rv->id }})"
                                            title="Оставить: запрос всё-таки наш">оставить</button>
                                @elseif($rv?->verdict === \App\Models\DirectQueryReview::OURS)
                                    <span class="chip text-[10px]" style="background:var(--emerald-50);color:var(--emerald-700)">наш</span>
                                @elseif($rv !== null)
                                    <span class="chip text-[10px]" style="background:var(--amber-50);color:var(--amber-800)"
                                          title="{{ $rv->reason }}">не уверена</span>
                                @endif

                                @if($row['auto'])
                                    <span class="chip text-[10px]" style="background:var(--sky-50);color:var(--sky-700)">подбор</span>
                                @endif
                                <span class="mono text-fg-2">{{ $row['impressions'] }}</span>
                                <span class="mono text-fg-4 w-[30px] text-right">{{ $row['clicks'] }}</span>
                            </div>
                        @empty
                            <p class="text-[12px] text-fg-4">Поисковых запросов в отчёте пока нет.</p>
                        @endforelse
                    </div>
                </div>
                <p class="text-[11.5px] text-fg-4 mt-2">
                    Столбцы: показы, клики. Минус-фраза уходит в ту кампанию, где запрос показался;
                    слова нашего мира («лифт», «поручень», «ремень» и подобные) минус-фразой стать не могут.
                </p>
            @endif
        </div>
    </div>

    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📋 Позиции</h3>
            <span class="text-[12px] text-fg-3">
                мест в показе <b class="mono">{{ $adsLimit }}</b>, держим готовыми <b class="mono">{{ $benchSize }}</b>;
                место занимает следующая позиция очереди, прошедшая модерацию
            </span>
            @if($campaign)
                <span class="chip text-[10.5px]" style="background:var(--emerald-50);color:var(--emerald-700)">
                    <span class="dot"></span>кампания #{{ $campaign['id'] }}
                </span>
            @else
                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">кампании ещё нет</span>
            @endif
            @if($warned)
                <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">
                    <span class="dot"></span>замечаний: {{ $warned }}
                </span>
            @endif
            <span class="flex-1"></span>

            <button type="button" class="btn btn-sm" wire:click="refreshQueue" title="Пересобрать очередь по свежим остаткам">↻ Очередь</button>
            @if($campaign)
                <button type="button" class="btn btn-sm" wire:click="refreshStates"
                        wire:loading.attr="disabled" wire:target="refreshStates" title="Спросить у Директа статусы">
                    <span wire:loading.remove wire:target="refreshStates">↻ Статусы</span>
                    <span wire:loading wire:target="refreshStates">Спрашиваю…</span>
                </button>
            @endif
            @if($pending)
                <button type="button" class="btn btn-sm" wire:click="generateMissing"
                        wire:loading.attr="disabled" wire:target="generateMissing"
                        title="Написать тексты всем позициям, где их ещё нет">
                    <span wire:loading.remove wire:target="generateMissing">✨ Тексты ({{ $pending }})</span>
                    <span wire:loading wire:target="generateMissing">Пишу…</span>
                </button>
            @endif
            @if(! $campaign)
                <button type="button" class="btn btn-sm btn-primary" wire:click="createCampaign"
                        wire:loading.attr="disabled" wire:target="createCampaign">
                    <span wire:loading.remove wire:target="createCampaign">Создать кампанию</span>
                    <span wire:loading wire:target="createCampaign">Создаю…</span>
                </button>
            @elseif($left)
                <button type="button" class="btn btn-sm btn-primary" wire:click="publishAds"
                        wire:loading.attr="disabled" wire:target="publishAds"
                        title="Создать группы, объявления-черновики и фразы. На модерацию ничего не уйдёт">
                    <span wire:loading.remove wire:target="publishAds">Опубликовать ({{ min($left, \App\Livewire\Direct\Index::PUBLISH_BATCH) }})</span>
                    <span wire:loading wire:target="publishAds">Публикую…</span>
                </button>
            @endif
            @if($drafts)
                <button type="button" class="btn btn-sm btn-primary" wire:click="moderateAds"
                        wire:loading.attr="disabled" wire:target="moderateAds"
                        title="После отправки каждая правка текста запускает проверку заново">
                    <span wire:loading.remove wire:target="moderateAds">На модерацию ({{ $drafts }})</span>
                    <span wire:loading wire:target="moderateAds">Отправляю…</span>
                </button>
            @endif
        </div>

        <div class="ds-card-body space-y-1.5">
            @if($publishLog)
                <div class="rounded-md border border-border-subtle p-2 space-y-0.5">
                    @foreach($publishLog as $line)
                        <div class="text-[12px] text-fg-2">{{ $line }}</div>
                    @endforeach
                </div>
            @endif

            {{-- Шапка списка: те же колонки, что и в строках --}}
            <div class="hidden md:flex items-center gap-2 px-3 text-fg-3 text-[10.5px] uppercase tracking-wide">
                <span class="w-[14px]"></span>
                <span class="w-[62px]">Артикул</span>
                <span class="flex-1">Заголовок объявления</span>
                <span class="w-[130px] text-right">Состояние</span>
                <span class="w-[54px] text-right">Фраз</span>
                <span class="w-[64px] text-right">Остаток</span>
                <span class="w-[60px] text-right">Заявок</span>
                <span class="w-[92px] text-right">Оплачено</span>
            </div>

            @forelse($plan as $p)
                @php [$stageLabel, $stageStyle] = $stage($p); $ad = $ads[$p['sku']] ?? null; @endphp

                @if($loop->index > 0 && ! $onAir($p) && $onAir($plan[$loop->index - 1]))
                    <div class="flex items-center gap-2 text-[11px] text-fg-4 py-1" wire:key="dp-line">
                        <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                        <span>ниже — резерв: тексты и модерация готовятся заранее, показов пока нет</span>
                        <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                    </div>
                @endif

                <div wire:key="dp-{{ $p['sku'] }}" class="border border-border rounded-md {{ $onAir($p) ? '' : 'opacity-75' }}"
                     x-data="{ open: false }">
                    <button type="button" class="w-full flex items-center gap-2 px-3 py-2 text-left" @click="open = ! open">
                        <span class="text-fg-4 text-[11px] w-[14px]" x-text="open ? '▾' : '▸'"></span>
                        <span class="mono text-[12px] text-fg-4 w-[62px]">{{ $p['sku'] }}</span>
                        <span class="flex-1 text-[13px] text-fg-1 truncate">{{ $p['title'] }}</span>
                        @if($p['warnings'])
                            <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">⚠</span>
                        @endif
                        <span class="w-[130px] text-right">
                            <span class="chip text-[10.5px]" style="{{ $stageStyle }}">{{ $stageLabel }}</span>
                        </span>
                        <span class="w-[54px] text-right mono text-[12px] text-fg-3">{{ count($p['keywords']) }}</span>
                        <span class="w-[64px] text-right mono text-[12px] text-fg-3">{{ $p['stock'] }}</span>
                        <span class="w-[60px] text-right mono text-[12px] text-fg-3">{{ $p['reqs'] }}</span>
                        <span class="w-[92px] text-right mono text-[12px] text-fg-3">{{ $money($p['paid']) }} ₽</span>
                    </button>

                    <div x-show="open" x-cloak class="px-3 pb-3 pt-1 border-t border-border-subtle space-y-2 text-[12.5px]">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[11.5px] text-fg-4">{{ $p['name'] }}</span>
                            <span class="flex-1"></span>
                            <button type="button" class="btn btn-sm" wire:click="generateAd('{{ $p['sku'] }}')"
                                    wire:loading.attr="disabled" wire:target="generateAd"
                                    title="Написать моделью все поля объявления в выбранном тоне">✨ Написать моделью</button>
                            @if($p['source'] !== 'rule')
                                <button type="button" class="btn btn-sm" wire:click="resetAd('{{ $p['sku'] }}')"
                                        title="Вернуть тексты, собранные правилами">↩ По правилам</button>
                            @endif
                            @if($ad?->isDraft())
                                <button type="button" class="btn btn-sm" wire:click="moderateAds('{{ $p['sku'] }}')"
                                        title="Отправить только это объявление">→ на модерацию</button>
                            @endif
                            <button type="button" class="btn btn-sm" x-data
                                    @click="$wire.excludeItem('{{ $p['sku'] }}', window.prompt('Почему убираем {{ $p['sku'] }} из рекламы? (можно пусто)', '') ?? '')"
                                    title="Снять позицию с рекламы: уйдёт из очереди, из фида и из кабинета">✕ Не рекламировать</button>
                        </div>

                        @foreach($fields as $f => [$label, $max])
                            <div wire:key="dp-{{ $p['sku'] }}-{{ $f }}" class="flex flex-wrap items-start gap-2"
                                 x-data="{ edit: false, draft: @js($p[$f]) }">
                                <span class="text-fg-3 w-[130px] shrink-0 pt-[3px]">{{ $label }}</span>
                                {{-- x-show, не x-if: внутри template Livewire не навешивает wire:click. --}}
                                <span x-show="! edit" class="flex flex-wrap items-center gap-2">
                                    <span class="text-fg-1">{{ $p[$f] }}</span>
                                    <span class="mono text-[11px] {{ mb_strlen($p[$f]) > $max ? 'text-red-600' : 'text-fg-4' }}">
                                        {{ mb_strlen($p[$f]) }}/{{ $max }}
                                    </span>
                                    <span class="chip text-[10.5px]" style="{{ $srcStyle($p['sources'][$f]) }}">
                                        {{ \App\Models\DirectAdText::SOURCES[$p['sources'][$f]] ?? $p['sources'][$f] }}
                                    </span>
                                    <button type="button" class="btn btn-sm" @click="edit = true">✎</button>
                                </span>
                                <span x-show="edit" x-cloak class="flex flex-wrap items-center gap-2 flex-1">
                                    <input type="text" x-model="draft" maxlength="{{ $max }}"
                                           class="{{ $inp }} flex-1 min-w-[260px]"
                                           @keydown.enter.prevent="$wire.saveField('{{ $p['sku'] }}', '{{ $f }}', draft); edit = false">
                                    <span class="mono text-[11px] text-fg-4" x-text="draft.length + '/{{ $max }}'"></span>
                                    <button type="button" class="btn btn-sm btn-primary"
                                            @click="$wire.saveField('{{ $p['sku'] }}', '{{ $f }}', draft); edit = false">Сохранить</button>
                                    <button type="button" class="btn btn-sm" @click="edit = false; draft = @js($p[$f])">Отмена</button>
                                </span>
                                @if($p['sources'][$f] !== 'rule' && $p['rule'][$f] !== $p[$f])
                                    <span class="basis-full text-[11px] text-fg-4 pl-[138px]">по правилу: {{ $p['rule'][$f] }}</span>
                                @endif
                            </div>
                        @endforeach

                        <div><span class="text-fg-3 w-[130px] inline-block align-top">Фразы</span>
                            @if($p['keywords'])
                                <span class="inline-flex flex-wrap gap-1">
                                    @foreach($p['keywords'] as $kw)
                                        <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-2)">{{ $kw }}</span>
                                    @endforeach
                                </span>
                            @else
                                <span class="text-amber-700">нет — у позиции не заполнены коды производителя</span>
                            @endif
                        </div>
                        <div><span class="text-fg-3 w-[130px] inline-block align-top">Ссылка</span>
                            <a href="{{ $p['url'] }}" target="_blank" rel="noopener"
                               class="text-sky-700 hover:underline break-all">{{ \Illuminate\Support\Str::limit($p['url'], 100) }}</a></div>
                        <div><span class="text-fg-3 w-[130px] inline-block">Цена</span>
                            <span class="mono">{{ $money($p['price']) }} ₽</span>
                            <span class="text-fg-4 text-[11.5px]">— в объявлении не публикуется</span></div>

                        @if($ad)
                            <div class="flex flex-wrap items-center gap-3 pt-1 border-t border-border-subtle text-[11.5px] text-fg-3">
                                <span>группа <span class="mono text-fg-2">{{ $ad->ad_group_id }}</span></span>
                                <span>объявление <span class="mono text-fg-2">{{ $ad->ad_id }}</span></span>
                                <span>статус <span class="text-fg-2">{{ $ad->statusLabel() }}</span></span>
                                @if($ad->state)<span>состояние <span class="mono text-fg-2">{{ $ad->state }}</span></span>@endif
                                @if($ad->published_at)<span>создано {{ $ad->published_at->format('d.m H:i') }}</span>@endif
                            </div>
                            @if($ad->status_note)
                                <div class="text-[11.5px] text-fg-3">{{ $ad->status_note }}</div>
                            @endif
                            @if($ad->last_error)
                                <div class="text-[12px] text-amber-800">⚠ {{ $ad->last_error }}</div>
                            @endif
                        @else
                            <div class="text-[11.5px] text-fg-4 pt-1 border-t border-border-subtle">
                                В Директе ещё не создана — уйдёт очередной публикацией.
                            </div>
                        @endif

                        @foreach($p['warnings'] as $wmsg)
                            <div class="text-[12px] text-amber-800">⚠ {{ $wmsg }}</div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="text-[13px] text-fg-3">Пусто: нет позиций с остатком и актуальной ценой.</div>
            @endforelse

            <div class="text-[11.5px] text-fg-4 pt-1">
                Цена в объявлениях не указывается: на карточке сайта её анонимному посетителю не видно,
                и расхождение текста со страницей ни к чему. Вместо неё — наличие и срок отгрузки.
                Годных к показу позиций всего: <b class="mono">{{ $money($this->readyCount) }}</b>.
            </div>

            @if($ops->isNotEmpty())
                <details class="pt-1">
                    <summary class="text-[12px] text-fg-3 cursor-pointer">Журнал операций ({{ $ops->count() }})</summary>
                    <div class="mt-1 space-y-0.5">
                        @foreach($ops as $op)
                            <div class="flex flex-wrap items-center gap-2 text-[11.5px] py-0.5 border-t border-border-subtle">
                                <span class="mono text-fg-4">{{ $op->created_at?->format('d.m H:i:s') }}</span>
                                <span class="mono {{ $op->ok ? 'text-fg-2' : 'text-amber-800' }}">{{ $op->title() }}</span>
                                @if($op->error_message)
                                    <span class="text-amber-800">{{ \Illuminate\Support\Str::limit($op->error_message, 70) }}</span>
                                @endif
                                <span class="flex-1"></span>
                                @if($op->units_spent !== null)
                                    <span class="text-fg-4">баллов: <span class="mono">{{ $op->units_spent }}</span></span>
                                @endif
                                <span class="text-fg-4">{{ $op->user?->name ?? 'система' }}</span>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    </div>

    {{-- Автосинхронизация с наличием --}}
    @php $sync = $this->syncState; @endphp
    <div class="ds-card">

    {{-- Ставки по аукциону --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">💸 Ставки</h3>
            <span class="text-[12px] text-fg-3">у каждой фразы свой порог входа — берём самую дешёвую ступень</span>
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="refreshBids"
                    wire:loading.attr="disabled" wire:target="refreshBids">
                <span wire:loading.remove wire:target="refreshBids">Опросить аукцион</span>
                <span wire:loading wire:target="refreshBids">Спрашиваю…</span>
            </button>
            @if($bidPlan)
                <button type="button" class="btn btn-sm btn-primary" wire:click="refreshBids(true)"
                        wire:loading.attr="disabled" wire:target="refreshBids"
                        title="Выставить ставки по аукциону, не выше потолка">
                    Применить ({{ $bidPlan['changes'] }})
                </button>
            @endif
        </div>
        <div class="ds-card-body space-y-2">
            <form wire:submit.prevent="saveBidCap" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Потолок ставки, ₽ за клик</label>
                    <input type="number" step="0.5" min="1" max="1000" wire:model="bidCap" class="{{ $inp }} w-[120px] mono">
                </div>
                <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                <span class="text-[11.5px] text-fg-4 flex-1">
                    Потолок — единственная защита от дорогого аукциона: по редкой позиции он иногда просит
                    сотни рублей за клик, и платить их за деталь в тысячу бессмысленно. Фразы, где вход дороже
                    потолка, остаются без показов — это видно в сводке ниже.
                </span>
            </form>

            @if($bidPlan)
                <div class="flex flex-wrap items-center gap-4 text-[12.5px]">
                    <span>фраз в кампании: <b class="mono">{{ $bidPlan['total'] }}</b></span>
                    <span>к изменению: <b class="mono text-fg-1">{{ $bidPlan['changes'] }}</b></span>
                    @if($bidPlan['at_cap'] > 0)
                        <span class="text-amber-800">вход дороже потолка: <b class="mono">{{ $bidPlan['at_cap'] }}</b></span>
                    @endif
                    <span class="text-fg-4">потолок {{ $bidPlan['cap'] }} ₽</span>
                </div>
            @else
                <div class="text-[12.5px] text-fg-3">
                    Нажмите «Опросить аукцион» — Директ покажет, сколько стоит вход по каждой нашей фразе.
                    Ставка ниже входа означает отсутствие показов: именно это и произошло со стартовыми 3 ₽.
                </div>
            @endif
        </div>
    </div>
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🔄 Синхронизация с наличием</h3>
            @php
                // Одна фраза вместо двух чипов: «включена» при режиме предложений
                // читалось как «работает», а прогон при этом ничего не менял.
                $live = $sync['enabled'] && ! $sync['dry'];
                $sState = match (true) {
                    $live => ['работает и меняет, раз в час', 'var(--emerald-50)', 'var(--emerald-700)'],
                    $sync['enabled'] => ['включена, но вхолостую: только показывает, что сделала бы', 'var(--amber-50)', 'var(--amber-800)'],
                    default => ['выключена', 'var(--neutral-100)', 'var(--fg-3)'],
                };
            @endphp
            <span class="chip text-[10.5px]" style="background:{{ $sState[1] }};color:{{ $sState[2] }}">
                <span class="dot"></span>{{ $sState[0] }}
            </span>
            <span class="flex-1"></span>
            @if($sync['last'])
                <span class="text-[11.5px] text-fg-4">последний прогон: {{ $sync['last'] }}</span>
            @endif
        </div>
        <div class="ds-card-body space-y-2">
            <div class="text-[12.5px] text-fg-2">
                Прогон ведёт весь конвейер: пишет тексты тем, у кого их нет, создаёт объявления,
                отправляет черновики на модерацию и держит в показе первые
                <b class="mono">{{ $adsLimit }}</b> позиций очереди из
                <b class="mono">{{ $sync['bench'] }}</b> подготовленных.
                Выпала позиция — гаснет, вернулась или подошла очередь — зажигается из резерва.
            </div>
            <div class="flex flex-wrap items-center gap-3 text-[12px] text-fg-3">
                <span>готовы к показу: <b class="mono text-fg-1">{{ $sync['ready'] }}</b></span>
                <span>идут показы: <b class="mono text-fg-1">{{ $sync['onAir'] }}</b></span>
                <span>ставки: <b class="mono text-fg-1">по аукциону</b>, потолок {{ \App\Services\Direct\DirectBidService::DEFAULT_CAP }} ₽</span>
                <span class="text-fg-4">
                    потолки за прогон: тексты {{ \App\Services\Direct\DirectSyncService::MAX_TEXTS }},
                    создание {{ \App\Services\Direct\DirectSyncService::MAX_PUBLISH }},
                    модерация {{ \App\Services\Direct\DirectSyncService::MAX_MODERATE }},
                    включение {{ \App\Services\Direct\DirectSyncService::MAX_RESUMES }};
                    выключение без ограничений
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-sm" wire:click="toggleSync('enabled')">
                    {{ $sync['enabled'] ? '⏸ Выключить автопрогон' : '▶ Включить автопрогон' }}
                </button>
                <button type="button" class="btn btn-sm {{ $sync['enabled'] && $sync['dry'] ? 'btn-primary' : '' }}"
                        wire:click="toggleSync('dry')">
                    {{ $sync['dry'] ? '🔓 Разрешить менять в Директе' : '🔒 Вернуть режим предложений' }}
                </button>
                @if($sync['enabled'] && $sync['dry'])
                    <span class="text-[11.5px] text-amber-800">
                        ← пока не нажмёте, прогон каждый час ничего не меняет
                    </span>
                @endif
                <span class="flex-1"></span>
                <button type="button" class="btn btn-sm" wire:click="runSync" wire:loading.attr="disabled" wire:target="runSync">
                    <span wire:loading.remove wire:target="runSync">Прогнать сейчас (предложения)</span>
                    <span wire:loading wire:target="runSync">Считаю…</span>
                </button>
                <button type="button" class="btn btn-sm btn-primary" wire:click="runSync(true)"
                        wire:loading.attr="disabled" wire:target="runSync"
                        title="Применить изменения в Директе прямо сейчас">Применить</button>
            </div>

            @if($syncReport)
                <div class="rounded-md border border-border-subtle p-2 space-y-0.5">
                    <div class="text-[12px] text-fg-3">
                        {{ $syncReport['applied'] ? 'Применено' : 'Предложения' }} ·
                        проверено <span class="mono">{{ $syncReport['checked'] }}</span> объявлений
                    </div>
                    @foreach($syncReport['suspend'] as $line)
                        <div class="text-[12px] text-amber-800">− выключить: {{ $line }}</div>
                    @endforeach
                    @foreach($syncReport['resume'] as $line)
                        <div class="text-[12px] text-emerald-700">+ включить: {{ $line }}</div>
                    @endforeach
                    @if($syncReport['texts'])
                        <div class="text-[12px] text-fg-2">✎ написать тексты: <span class="mono">{{ implode(', ', $syncReport['texts']) }}</span></div>
                    @endif
                    @if($syncReport['published'])
                        <div class="text-[12px] text-fg-2">＋ создать объявления: <span class="mono">{{ implode(', ', $syncReport['published']) }}</span></div>
                    @endif
                    @if($syncReport['moderated'])
                        <div class="text-[12px] text-fg-2">→ на модерацию: <span class="mono">{{ implode(', ', $syncReport['moderated']) }}</span></div>
                    @endif
                    @if(($syncReport['bids_set'] ?? 0) > 0)
                        <div class="text-[12px] text-fg-2">💸 ставок обновлено по аукциону: <span class="mono">{{ $syncReport['bids_set'] }}</span></div>
                    @endif
                    @if($syncReport['retired'] ?? [])
                        <div class="text-[12px] text-fg-2">✕ убрать из кабинета: <span class="mono">{{ implode(', ', $syncReport['retired']) }}</span></div>
                    @endif
                    @if($syncReport['fixed'] ?? [])
                        <div class="text-[12px] text-fg-2">↻ переписать после отказа: <span class="mono">{{ implode(', ', $syncReport['fixed']) }}</span></div>
                    @endif
                    @foreach($syncReport['attention'] as $line)
                        <div class="text-[12px] text-red-700">⚠ требует внимания: {{ $line }}</div>
                    @endforeach
                    @if(! $syncReport['suspend'] && ! $syncReport['resume'] && ! $syncReport['texts']
                        && ! $syncReport['published'] && ! $syncReport['moderated'])
                        <div class="text-[12px] text-fg-3">Менять нечего — конвейер в равновесии.</div>
                    @endif
                    @foreach($syncReport['errors'] as $line)
                        <div class="text-[12px] text-red-700">! {{ $line }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    {{-- Снятые с рекламы вручную --}}
    @php $excluded = $this->excluded; @endphp
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🚫 Не рекламируем</h3>
            <span class="text-[12px] text-fg-3">сняты вручную — не попадают ни в очередь, ни в фид</span>
            <span class="flex-1"></span>
            <span class="mono text-[12px] text-fg-4">{{ $excluded->count() }}</span>
        </div>
        <div class="ds-card-body">
            @forelse($excluded as $ex)
                <div wire:key="dx-{{ $ex->id }}"
                     class="flex flex-wrap items-center gap-2 py-2 {{ ! $loop->last ? 'border-b border-border-subtle' : '' }}">
                    <span class="mono text-[12.5px] text-fg-1">{{ $ex->sku }}</span>
                    <span class="text-[12.5px] text-fg-2">
                        {{ \Illuminate\Support\Str::limit($ex->catalogItem?->name ?? '—', 52) }}
                    </span>
                    @if($ex->reason)
                        <span class="text-[12px] text-fg-3">· {{ \Illuminate\Support\Str::limit($ex->reason, 60) }}</span>
                    @endif
                    <span class="flex-1"></span>
                    <span class="text-[11px] text-fg-4">
                        {{ $ex->excludedBy?->name ?? 'система' }} · {{ $ex->created_at?->format('d.m.Y') }}
                    </span>
                    <button type="button" class="btn btn-sm" wire:click="restoreItem('{{ $ex->sku }}')"
                            title="Вернуть позицию в очередь">↩ Вернуть</button>
                </div>
            @empty
                <div class="text-[13px] text-fg-3">
                    Пока никого. Сюда попадают позиции, которые проходят по складу и цене, но сами по себе
                    спросом не пользуются — комплектующие к другому товару, расходники, упаковка.
                </div>
            @endforelse
        </div>
    </div>
</div>
