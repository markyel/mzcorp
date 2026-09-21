@php
    $inp = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $queue = $this->queue;
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
                    <label class="block text-[11.5px] text-fg-3 mb-1">Объявлений одновременно</label>
                    <input type="number" wire:model="adsLimit" min="{{ \App\Livewire\Direct\Index::MIN_ADS_LIMIT }}"
                           max="{{ \App\Livewire\Direct\Index::MAX_ADS_LIMIT }}" class="{{ $inp }} w-[120px] mono">
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

    {{-- Очередь позиций --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📋 Очередь позиций</h3>
            <span class="text-[12px] text-fg-3">по деньгам за 12 месяцев</span>
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="refreshQueue">↻ Пересобрать</button>
        </div>
        <div class="ds-card-body">
            @if($queue->isEmpty())
                <div class="text-[13px] text-fg-3">Пусто: нет позиций с остатком и актуальной ценой.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]" style="border-collapse:collapse">
                        <thead>
                            <tr class="text-fg-3 text-[11px] uppercase tracking-wide">
                                <th class="text-left py-1.5 pr-2">#</th>
                                <th class="text-left py-1.5 pr-2">Артикул</th>
                                <th class="text-left py-1.5 pr-2">Позиция</th>
                                <th class="text-right py-1.5 pr-2">Цена</th>
                                <th class="text-right py-1.5 pr-2">Остаток</th>
                                <th class="text-right py-1.5 pr-2">Заявок</th>
                                <th class="text-right py-1.5 pr-2">Оплачено</th>
                                <th class="text-left py-1.5 pr-2">Коды</th>
                                <th class="py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($queue as $i => $row)
                                @php
                                    $inWork = $i < $adsLimit;
                                    $codes = \App\Services\Catalog\YandexDirectFeedService::codes($row);
                                @endphp
                                <tr wire:key="dq-{{ $row->sku }}" class="border-t border-border-subtle {{ $inWork ? '' : 'opacity-55' }}">
                                    <td class="py-1.5 pr-2 mono text-fg-4">{{ $i + 1 }}</td>
                                    <td class="py-1.5 pr-2 mono {{ $inWork ? 'text-fg-1' : 'text-fg-3' }}">{{ $row->sku }}</td>
                                    <td class="py-1.5 pr-2">
                                        <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($row->name, 54) }}</div>
                                        @if($row->brand)<div class="text-[11px] text-fg-4">{{ $row->brand }}</div>@endif
                                    </td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ $money($row->price) }} ₽</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ (int) $row->stock_available }}</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ (int) $row->reqs }}</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ $money($row->paid) }} ₽</td>
                                    <td class="py-1.5 pr-2 text-[11.5px] text-fg-3">
                                        {{ $codes ? \Illuminate\Support\Str::limit(implode(', ', $codes), 30) : '—' }}
                                    </td>
                                    <td class="py-1.5 text-right">
                                        {{-- Причину спрашиваем сразу: через месяц «почему эта позиция снята»
                                             по одному артикулу уже не восстановить. --}}
                                        <button type="button" class="btn btn-sm"
                                                x-data
                                                @click="$wire.excludeItem('{{ $row->sku }}', window.prompt('Почему убираем {{ $row->sku }} из рекламы? (можно пусто)', '') ?? '')"
                                                title="Убрать позицию из рекламы — и из очереди, и из фида">✕ Не рекламировать</button>
                                    </td>
                                </tr>
                                @if($inWork && $i + 1 === $adsLimit)
                                    <tr wire:key="dq-line">
                                        <td colspan="9" class="py-1">
                                            <div class="flex items-center gap-2 text-[11px] text-fg-4">
                                                <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                                                <span>граница: выше — в работе, ниже — в очереди</span>
                                                <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- План объявлений --}}
    @php
        $plan = $this->plan;
        $warned = $plan->filter(fn ($p) => $p['warnings'] !== [])->count();
        $pending = $plan->filter(fn ($p) => $p['source'] === 'rule')->count();
        $fields = [
            'title' => ['Заголовок', \App\Services\Direct\DirectAdPlanService::TITLE_MAX],
            'title2' => ['Второй заголовок', \App\Services\Direct\DirectAdPlanService::TITLE2_MAX],
            'text' => ['Текст', \App\Services\Direct\DirectAdPlanService::TEXT_MAX],
        ];
        $srcStyle = fn ($s) => $s === 'manual'
            ? 'background:var(--emerald-50);color:var(--emerald-700)'
            : ($s === 'ai' ? 'background:var(--sky-50);color:var(--sky-700)' : 'background:var(--neutral-100);color:var(--fg-3)');
    @endphp
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📝 Объявления</h3>
            <span class="text-[12px] text-fg-3">пишем заранее на всю очередь · в Директ пока ничего не создано</span>
            @if($warned)
                <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">
                    <span class="dot"></span>замечаний: {{ $warned }}
                </span>
            @endif
            <span class="flex-1"></span>
            <span class="text-[12px] text-fg-3">кампания: <span class="mono text-fg-2">{{ \App\Services\Direct\DirectAdPlanService::campaignName() }}</span></span>
            @if($pending)
                <button type="button" class="btn btn-sm" wire:click="generateMissing"
                        wire:loading.attr="disabled" wire:target="generateMissing"
                        title="Написать тексты всем позициям, где их ещё нет — включая те, что ждут за порогом">
                    <span wire:loading.remove wire:target="generateMissing">✨ Написать недостающие ({{ $pending }})</span>
                    <span wire:loading wire:target="generateMissing">Пишу…</span>
                </button>
            @endif
        </div>
        <div class="ds-card-body space-y-2">
            <div class="text-[11.5px] text-fg-4">
                Тексты готовятся до публикации: у работающего объявления любая правка — повторная
                модерация, поэтому очередь «на подходе» пишем и вычитываем заранее.
            </div>

            @forelse($plan as $p)
                @if(! $p['in_rotation'] && ($plan[$loop->index - 1]['in_rotation'] ?? false))
                    <div class="flex items-center gap-2 text-[11px] text-fg-4 py-1" wire:key="dp-line">
                        <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                        <span>ниже — резерв: в ротацию не уйдут, но тексты готовим заранее</span>
                        <span class="flex-1" style="height:1px;background:var(--border-strong)"></span>
                    </div>
                @endif

                <div wire:key="dp-{{ $p['sku'] }}" class="border border-border rounded-md {{ $p['in_rotation'] ? '' : 'opacity-70' }}"
                     x-data="{ open: false }">
                    <button type="button" class="w-full flex flex-wrap items-center gap-2 px-3 py-2 text-left" @click="open = ! open">
                        <span class="text-fg-4 text-[11px]" x-text="open ? '▾' : '▸'"></span>
                        <span class="mono text-[12px] text-fg-4">{{ $p['sku'] }}</span>
                        <span class="text-[13px] text-fg-1">{{ $p['title'] }}</span>
                        <span class="chip text-[10.5px]" style="{{ $srcStyle($p['source']) }}">
                            {{ \App\Models\DirectAdText::SOURCES[$p['source']] ?? $p['source'] }}
                        </span>
                        <span class="flex-1"></span>
                        @unless($p['in_rotation'])
                            <span class="text-[11px] text-fg-4">резерв</span>
                        @endunless
                        <span class="text-[11.5px] text-fg-3">фраз: <span class="mono">{{ count($p['keywords']) }}</span></span>
                        @if($p['warnings'])
                            <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">⚠</span>
                        @endif
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
                        </div>

                        {{-- Поля объявления: правятся по месту, правка сильнее модели. --}}
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
                                    <span class="basis-full text-[11px] text-fg-4 pl-[138px]">
                                        по правилу: {{ $p['rule'][$f] }}
                                    </span>
                                @endif
                            </div>
                        @endforeach

                        <div><span class="text-fg-3 w-[130px] inline-block align-top">Ссылка</span>
                            <a href="{{ $p['url'] }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline break-all">{{ \Illuminate\Support\Str::limit($p['url'], 110) }}</a></div>
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
                        <div><span class="text-fg-3 w-[130px] inline-block">Группа</span><span class="mono text-[11.5px]">{{ $p['group'] }}</span></div>
                        @foreach($p['warnings'] as $wmsg)
                            <div class="text-[12px] text-amber-800">⚠ {{ $wmsg }}</div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="text-[13px] text-fg-3">Очередь пуста — плану не из чего собираться.</div>
            @endforelse

            <div class="text-[11.5px] text-fg-4 pt-1">
                Цена в объявлениях не указывается: на карточке сайта её анонимному посетителю не видно,
                и расхождение текста со страницей ни к чему. Вместо неё — наличие и срок отгрузки.
            </div>
        </div>
    </div>


    {{-- Публикация в Директ --}}
    @php
        $campaign = $this->campaign;
        $ads = $this->publishedAds;
        $ops = $this->operations;
        $readyToPublish = $plan->filter(fn ($p) => $p['in_rotation'] && $p['keywords'] !== [])->count();
        // «Сделано» — это доведённое до конца объявление, а не просто строка в
        // таблице: запись заводится и на неудачной попытке, и её надо доделать.
        $doneSkus = $ads->filter(fn ($a) => $a->isComplete())->pluck('sku')->all();
        $left = $plan->filter(fn ($p) => $p['in_rotation'] && $p['keywords'] !== [] && ! in_array($p['sku'], $doneSkus, true))->count();
    @endphp
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🚀 Публикация в Директ</h3>
            @if($campaign)
                <span class="chip text-[10.5px]" style="background:var(--emerald-50);color:var(--emerald-700)">
                    <span class="dot"></span>кампания #{{ $campaign['id'] }}
                </span>
            @else
                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">кампании ещё нет</span>
            @endif
            <span class="flex-1"></span>
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
        </div>
        <div class="ds-card-body space-y-2">
            <div class="text-[11.5px] text-fg-4">
                Кампания создаётся остановленной, объявления — черновиками: показов нет и денег не тратится,
                пока вы сами не отправите их на модерацию и не запустите кампанию.
                Регион: <span class="mono">{{ implode(', ', \App\Services\Direct\DirectPublisherService::regionIds()) }}</span>,
                ставка фразы: <span class="mono">{{ \App\Services\Direct\DirectPublisherService::defaultBid() }} ₽</span>,
                дневной бюджет: <span class="mono">{{ (float) config('services.yandex_direct.daily_budget') }} ₽</span>.
            </div>

            @if($publishLog)
                <div class="rounded-md border border-border-subtle p-2 space-y-0.5">
                    @foreach($publishLog as $line)
                        <div class="text-[12px] text-fg-2">{{ $line }}</div>
                    @endforeach
                </div>
            @endif

            @if($ads->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]" style="border-collapse:collapse">
                        <thead>
                            <tr class="text-fg-3 text-[11px] uppercase tracking-wide">
                                <th class="text-left py-1.5 pr-2">Артикул</th>
                                <th class="text-left py-1.5 pr-2">Заголовок</th>
                                <th class="text-right py-1.5 pr-2">Группа</th>
                                <th class="text-right py-1.5 pr-2">Объявление</th>
                                <th class="text-right py-1.5 pr-2">Фраз</th>
                                <th class="text-left py-1.5 pr-2">Состояние</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ads as $ad)
                                <tr wire:key="da-{{ $ad->id }}" class="border-t border-border-subtle">
                                    <td class="py-1.5 pr-2 mono">{{ $ad->sku }}</td>
                                    <td class="py-1.5 pr-2">{{ \Illuminate\Support\Str::limit($ad->title, 48) }}</td>
                                    <td class="py-1.5 pr-2 text-right mono text-fg-4">{{ $ad->ad_group_id ?? '—' }}</td>
                                    <td class="py-1.5 pr-2 text-right mono text-fg-4">{{ $ad->ad_id ?? '—' }}</td>
                                    <td class="py-1.5 pr-2 text-right mono">{{ count($ad->keyword_ids ?? []) }}</td>
                                    <td class="py-1.5 pr-2">
                                        @if($ad->last_error)
                                            <span class="text-amber-800">{{ \Illuminate\Support\Str::limit($ad->last_error, 60) }}</span>
                                        @else
                                            <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">
                                                {{ $ad->state ?? 'черновик' }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-[13px] text-fg-3">
                    Пока ничего не опубликовано. Готовых к публикации позиций в ротации:
                    <b class="mono">{{ $readyToPublish }}</b> — у них есть тексты и фразы.
                </div>
            @endif

            {{-- Журнал: что уходило в Директ и во что обошлось --}}
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
