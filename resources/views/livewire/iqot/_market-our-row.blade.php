{{-- Наша цена в списке цен рынка IQOT, на своём месте по цене без НДС. Ожидает $n, $market, $rub. --}}
<tr class="border-t border-border-subtle align-top bg-red-50">
    <td class="py-1 pr-2 font-bold text-red-700">{{ $n }}</td>
    <td class="py-1 pr-2 whitespace-nowrap text-red-700"><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-600 text-white mr-1">МЫ</span>{{ $market['our_label'] ?? 'Наша цена' }}</td>
    <td class="py-1 pr-2"></td>
    <td class="py-1 pr-2 whitespace-nowrap mono text-right text-red-700">
        {{ $rub($market['our_gross']) }}
        <div class="text-[10px] text-fg-4">с НДС</div>
    </td>
    <td class="py-1 pr-2 whitespace-nowrap mono text-right font-semibold text-red-700">{{ $rub($market['our_net']) }}</td>
    <td colspan="2"></td>
</tr>
