{{-- Коммерческое предложение для клиента. Верстается под dompdf: только
     таблицы и инлайновые стили, без flex и grid — их движок не понимает. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: "PT Sans", sans-serif; font-size: 10pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2mm; }
        .meta { font-size: 9pt; color: #555; margin-bottom: 6mm; }
        table { width: 100%; border-collapse: collapse; }
        th { font-size: 8.5pt; text-transform: uppercase; letter-spacing: .3pt; color: #555;
             border-bottom: 1px solid #999; padding: 0 2mm 1.5mm; text-align: left; }
        td { padding: 1.8mm 2mm; border-bottom: 1px solid #e3e3e3; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-family: "PT Mono", monospace; }
        .total td { border-top: 1px solid #999; border-bottom: none; font-size: 11pt; padding-top: 3mm; }
        .note { font-size: 9pt; color: #444; margin-top: 7mm; line-height: 1.5; }
        .sign { font-size: 9.5pt; margin-top: 10mm; }
        .muted { color: #777; }
    </style>
</head>
<body>
    <h1>Коммерческое предложение</h1>
    <div class="meta">
        по заявке {{ $request->internal_code }} от {{ $date }}<br>
        @if($organization){{ $organization }}<br>@endif
        {{ $company }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 7mm;">№</th>
                <th style="width: 22mm;">Артикул</th>
                <th>Наименование</th>
                <th class="num" style="width: 22mm;">Кол-во</th>
                <th class="num" style="width: 26mm;">Цена</th>
                <th class="num" style="width: 28mm;">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['sku'] ?? '' }}</td>
                    <td>{{ $line['name'] ?? '' }}</td>
                    <td class="num">{{ $line['qty_label'] }}</td>
                    <td class="num">{{ $line['price_label'] }}</td>
                    <td class="num">{{ $line['total_label'] }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5" class="num">Итого</td>
                <td class="num"><b>{{ $totalLabel }}</b></td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        Цены указаны в рублях{{ $vatNote }}. Товар на складе, счёт выставляем в день обращения.<br>
        Предложение действительно {{ $validDays }} дней — до {{ $validUntil }}.
    </div>

    <div class="sign">
        {{ $manager }}<br>
        @if($managerEmail)<span class="muted">{{ $managerEmail }}</span>@endif
    </div>
</body>
</html>
