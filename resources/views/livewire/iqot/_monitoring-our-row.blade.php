{{-- Наша цена каталога в таблице мониторинга IQOT, на своём месте по цене без НДС. Ожидает $n, $cmp, $rub, $actual. --}}
<tr class="border-t border-border-subtle align-top bg-red-50">
    <td class="py-1 pr-2 font-bold text-red-700">{{ $n }}</td>
    <td class="py-1 pr-2 whitespace-nowrap text-red-700"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-600 text-white mr-1">МЫ</span>цена каталога</td>
    <td class="py-1 pr-2 whitespace-nowrap mono text-right text-red-700">
        {{ $rub($cmp['our_net'] * (1 + (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22)) / 100)) }}
        <div class="text-[10px] text-fg-4">с НДС</div>
    </td>
    <td class="py-1 pr-2 whitespace-nowrap mono text-right font-semibold text-red-700">{{ $rub($cmp['our_net']) }}</td>
    <td colspan="3" class="py-1 text-[11px] text-fg-4">{{ $actual ? '' : 'цена в каталоге не актуальна' }}</td>
</tr>
