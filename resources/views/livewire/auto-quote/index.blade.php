@php
    $inp = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $rows = $this->visible;
    $summary = $this->summary;
    $labels = \App\Services\Quotes\AutoQuoteComparisonService::LABELS;
    $kindStyle = fn ($k) => match ($k) {
        'same' => 'background:var(--emerald-50);color:var(--emerald-700)',
        'price' => 'background:var(--amber-50);color:var(--amber-800)',
        'nomenclature', 'composition' => 'background:var(--red-50);color:var(--red-700)',
        default => 'background:var(--neutral-100);color:var(--fg-3)',
    };
@endphp

<div class="space-y-4">

    {{-- Сводка: ради неё прогон и затеян --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🤖 Авто-КП: холостой прогон</h3>
            <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">ничего не отправляется</span>
            <span class="flex-1"></span>
            <label class="text-[12px] text-fg-3">окно, дней</label>
            <input type="number" wire:model.live.debounce.500ms="days" min="1" max="180" class="{{ $inp }} w-[80px] mono">
        </div>
        <div class="ds-card-body space-y-2">
            <div class="text-[12.5px] text-fg-2">
                Автомат выдаёт только КП и только по однострочной заявке, где клиент сам написал наш артикул,
                цена актуальна, сумма до {{ number_format(\App\Services\Quotes\AutoQuoteRuleService::MAX_TOTAL, 0, ',', ' ') }} ₽
                и счёт не просят прямым текстом. Здесь видно, что он выдал бы и чем это расходится
                с тем, что ушло клиенту на самом деле.
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="setKind('all')"
                        class="px-3 py-1.5 rounded-md border text-[12.5px]"
                        style="border-color:{{ $kind === 'all' ? 'var(--sky-500)' : 'var(--border)' }}">
                    все · <span class="mono">{{ array_sum($summary) }}</span>
                </button>
                @foreach($labels as $key => $label)
                    <button type="button" wire:key="k-{{ $key }}" wire:click="setKind('{{ $key }}')"
                            class="px-3 py-1.5 rounded-md border text-[12.5px]"
                            style="border-color:{{ $kind === $key ? 'var(--sky-500)' : 'var(--border)' }}">
                        <span class="chip text-[10.5px]" style="{{ $kindStyle($key) }}">{{ $label }}</span>
                        <span class="mono ml-1">{{ $summary[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            @php $total = array_sum($summary); @endphp
            @if($total > 0)
                <div class="text-[11.5px] text-fg-4">
                    Совпало с менеджером: <b class="mono text-fg-2">{{ round(($summary['same'] ?? 0) / $total * 100) }}%</b>.
                    Расхождения по номенклатуре и составу — это экспертиза менеджера поверх буквального запроса:
                    подобранная замена, дополненный комплект. Там и проходит граница автомата.
                </div>
            @endif
        </div>
    </div>

    {{-- Список заявок --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">Заявки под автомат</h3>
            <span class="text-[12px] text-fg-3">за {{ $days }} дн. · показано {{ count($rows) }}</span>
        </div>
        <div class="ds-card-body space-y-1.5">
            @forelse($rows as $row)
                @php
                    $r = $row['request'];
                    $v = $row['verdict'];
                    $c = $row['comparison'];
                @endphp
                <div wire:key="aq-{{ $r->id }}" class="border border-border rounded-md" x-data="{ open: false }">
                    <button type="button" class="w-full flex flex-wrap items-center gap-2 px-3 py-2 text-left" @click="open = ! open">
                        <span class="text-fg-4 text-[11px]" x-text="open ? '▾' : '▸'"></span>
                        <a href="{{ route('requests.show', $r->id) }}" target="_blank"
                           class="mono text-[12.5px] text-sky-700 hover:underline" @click.stop>{{ $r->code }}</a>
                        <span class="text-[13px] text-fg-1 truncate max-w-[260px]">{{ $r->client_name ?: $r->client_email }}</span>
                        <span class="text-[12px] text-fg-3 truncate max-w-[280px]">{{ $v['lines'][0]['name'] ?? '' }}</span>
                        <span class="flex-1"></span>
                        <span class="chip text-[10.5px]" style="{{ $kindStyle($c['kind']) }}">{{ $c['label'] }}</span>
                        <span class="mono text-[12px] text-fg-2 w-[110px] text-right">{{ $money($v['total']) }} ₽</span>
                        <span class="mono text-[12px] text-fg-4 w-[110px] text-right">
                            {{ $c['total_actual'] !== null ? $money($c['total_actual']).' ₽' : '—' }}
                        </span>
                        <span class="text-[11px] text-fg-4 w-[92px] text-right">{{ $r->created_at?->format('d.m H:i') }}</span>
                    </button>

                    <div x-show="open" x-cloak class="px-3 pb-3 pt-1 border-t border-border-subtle space-y-2 text-[12.5px]">
                        <div class="flex flex-wrap items-center gap-3 text-[11.5px] text-fg-3">
                            <span>менеджер: <span class="text-fg-2">{{ $r->assignedUser?->name ?? '—' }}</span></span>
                            <span>статус: <span class="text-fg-2">{{ $r->status?->label() ?? $r->status }}</span></span>
                            @if($c['document'])
                                <span>документ: <span class="text-fg-2">{{ $c['document']['label'] }}
                                    {{ $c['document']['number'] }}</span>
                                    @if($c['document']['date'])<span class="text-fg-4">от {{ $c['document']['date'] }}</span>@endif
                                </span>
                            @else
                                <span class="text-amber-800">клиенту ничего не ушло</span>
                            @endif
                        </div>

                        {{-- Построчное сравнение: слева автомат, справа факт --}}
                        <div class="overflow-x-auto">
                            <table class="w-full text-[12.5px]" style="border-collapse:collapse">
                                <thead>
                                    <tr class="text-fg-3 text-[10.5px] uppercase tracking-wide">
                                        <th class="text-left py-1.5 pr-2">Артикул</th>
                                        <th class="text-left py-1.5 pr-2">Позиция</th>
                                        <th class="text-right py-1.5 pr-2">Авто: кол-во</th>
                                        <th class="text-right py-1.5 pr-2">Авто: цена</th>
                                        <th class="text-right py-1.5 pr-2">Факт: кол-во</th>
                                        <th class="text-right py-1.5 pr-2">Факт: цена</th>
                                        <th class="text-right py-1.5">Разница</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($c['rows'] as $line)
                                        <tr class="border-t border-border-subtle"
                                            style="{{ $line['only_auto'] || $line['only_fact'] ? 'background:var(--red-50)' : ($line['price_differs'] || $line['qty_differs'] ? 'background:var(--amber-50)' : '') }}">
                                            <td class="py-1.5 pr-2 mono">{{ $line['sku'] ?: '—' }}</td>
                                            <td class="py-1.5 pr-2">{{ \Illuminate\Support\Str::limit($line['name'], 54) }}
                                                @if($line['only_auto'])
                                                    <span class="text-[11px] text-red-700">— только у автомата</span>
                                                @elseif($line['only_fact'])
                                                    <span class="text-[11px] text-red-700">— добавил менеджер</span>
                                                @endif
                                            </td>
                                            <td class="py-1.5 pr-2 text-right mono">{{ $line['auto'] ? rtrim(rtrim(number_format($line['auto']['qty'], 3, ',', ' '), '0'), ',') : '—' }}</td>
                                            <td class="py-1.5 pr-2 text-right mono">{{ $line['auto'] ? $money($line['auto']['unit_price']) : '—' }}</td>
                                            <td class="py-1.5 pr-2 text-right mono {{ $line['qty_differs'] ? 'text-amber-800 font-medium' : '' }}">
                                                {{ $line['fact'] ? rtrim(rtrim(number_format($line['fact']['qty'], 3, ',', ' '), '0'), ',') : '—' }}
                                            </td>
                                            <td class="py-1.5 pr-2 text-right mono {{ $line['price_differs'] ? 'text-amber-800 font-medium' : '' }}">
                                                {{ $line['fact'] ? $money($line['fact']['unit_price']) : '—' }}
                                            </td>
                                            <td class="py-1.5 text-right mono text-[11.5px]">
                                                @if($line['price_delta'] !== null && abs($line['price_delta']) > 0.005)
                                                    <span class="{{ $line['price_delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                                        {{ $line['price_delta'] > 0 ? '+' : '' }}{{ $money($line['price_delta']) }}
                                                    </span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Что просил клиент — чтобы видеть, с чего всё началось --}}
                        <div class="text-[11.5px] text-fg-3">
                            Клиент просил: <span class="mono text-fg-2">{{ $v['lines'][0]['asked'] ?? '—' }}</span>
                            @if(($v['lines'][0]['discount_percent'] ?? 0) > 0)
                                <span class="text-fg-4">· скидка клиента
                                    {{ rtrim(rtrim(number_format($v['lines'][0]['discount_percent'], 2, ',', ' '), '0'), ',') }}%
                                    от каталожной {{ $money($v['lines'][0]['catalog_price']) }} ₽</span>
                            @endif
                            @if(($v['lines'][0]['stock'] ?? 0) > 0)
                                <span class="text-emerald-700">· на складе {{ $v['lines'][0]['stock'] }}</span>
                            @endif
                        </div>

                        <details>
                            <summary class="text-[11.5px] text-fg-3 cursor-pointer">Почему заявка прошла правило</summary>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                                @foreach($v['checks'] as $check)
                                    <span class="text-[11.5px] {{ $check['ok'] ? 'text-fg-3' : 'text-amber-800' }}">
                                        {{ $check['ok'] ? '✓' : '✕' }} {{ $check['label'] }}
                                        <span class="text-fg-4">— {{ $check['detail'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </details>
                    </div>
                </div>
            @empty
                <div class="text-[13px] text-fg-3">
                    За выбранное окно заявок под автомат не нашлось. Попробуйте увеличить число дней.
                </div>
            @endforelse
        </div>
    </div>
</div>
