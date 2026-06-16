<div>
    <div class="flex items-center justify-between gap-4 mb-3 flex-wrap">
        <div>
            <h3 class="text-[15px] font-semibold text-fg-1 m-0">Изменения цен каталога</h3>
            <p class="text-[12px] text-fg-3 m-0 mt-0.5">Ретроспектива «было → стало» по позициям. Источник — импорт каталога из MDB.</p>
        </div>
        <a href="{{ route('analytics.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline font-medium">← К аналитике</a>
    </div>

    {{-- Фильтры --}}
    <div class="flex items-center gap-3 mb-3 flex-wrap text-[12.5px]">
        <div class="inline-flex rounded-md border border-border overflow-hidden">
            @foreach(['30' => '30 дн', '90' => '90 дн', '365' => 'год', 'all' => 'всё время'] as $p => $label)
                <button type="button" wire:click="setPeriod('{{ $p }}')"
                        class="px-3 py-1.5 {{ $period === $p ? 'bg-[var(--accent)] text-fg-on-accent font-semibold' : 'bg-surface text-fg-2 hover:bg-[var(--bg-hover)]' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="inline-flex rounded-md border border-border overflow-hidden">
            @foreach(['all' => 'все', 'up' => '▲ подорожания', 'down' => '▼ удешевления'] as $d => $label)
                <button type="button" wire:click="setDirection('{{ $d }}')"
                        class="px-3 py-1.5 {{ $direction === $d ? 'bg-[var(--accent)] text-fg-on-accent font-semibold' : 'bg-surface text-fg-2 hover:bg-[var(--bg-hover)]' }}">{{ $label }}</button>
            @endforeach
        </div>
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="SKU или название…"
               class="flex-1 min-w-[200px] rounded-md border-border text-[12.5px]">
    </div>

    @if($changes->isEmpty())
        <div class="rounded-md border border-border bg-surface p-8 text-center text-fg-3 text-[13px]">
            Изменений цен за выбранный период не найдено. Данные накапливаются с каждым импортом каталога.
        </div>
    @else
        <div class="bg-surface border border-border rounded-md overflow-hidden">
            <table class="w-full text-[12.5px]">
                <thead class="bg-surface-2 text-fg-3 text-[10.5px] uppercase tracking-wider">
                    <tr>
                        <th class="text-left px-3 py-2">Дата</th>
                        <th class="text-left px-3 py-2">SKU</th>
                        <th class="text-left px-3 py-2">Наименование</th>
                        <th class="text-right px-3 py-2">Было</th>
                        <th class="text-right px-3 py-2">Стало</th>
                        <th class="text-right px-3 py-2">Δ</th>
                        <th class="text-right px-3 py-2">%</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @foreach($changes as $c)
                        @php
                            $delta = $c->priceDelta();
                            $pct = ($delta !== null && (float) $c->old_price != 0.0)
                                ? round($delta / (float) $c->old_price * 100, 1) : null;
                            $tone = $delta === null ? 'text-fg-3' : ($delta > 0 ? 'text-red-600' : ($delta < 0 ? 'text-emerald-700' : 'text-fg-3'));
                            $arrow = $delta === null ? '' : ($delta > 0 ? '▲' : ($delta < 0 ? '▼' : '='));
                        @endphp
                        <tr wire:key="pc-{{ $c->id }}" class="hover:bg-[var(--bg-hover)]">
                            <td class="px-3 py-2 text-fg-3 whitespace-nowrap">{{ $c->changed_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-2 mono font-semibold text-fg-1 whitespace-nowrap">{{ $c->sku }}</td>
                            <td class="px-3 py-2 text-fg-2">{{ \Illuminate\Support\Str::limit($c->catalogItem?->name ?? '—', 60) }}</td>
                            <td class="px-3 py-2 text-right mono text-fg-2 whitespace-nowrap">{{ $c->old_price !== null ? number_format((float) $c->old_price, 2, ',', ' ') : '—' }}</td>
                            <td class="px-3 py-2 text-right mono font-semibold text-fg-1 whitespace-nowrap">{{ $c->new_price !== null ? number_format((float) $c->new_price, 2, ',', ' ') : '—' }}</td>
                            <td class="px-3 py-2 text-right mono font-semibold {{ $tone }} whitespace-nowrap">{{ $arrow }} {{ $delta !== null ? number_format($delta, 2, ',', ' ') : '—' }}</td>
                            <td class="px-3 py-2 text-right mono {{ $tone }} whitespace-nowrap">{{ $pct !== null ? ($pct > 0 ? '+' : '') . $pct . '%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $changes->links() }}</div>
    @endif
</div>
