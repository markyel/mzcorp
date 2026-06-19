@php
    $lang = ($lang ?? 'ru') === 'en' ? 'en' : 'ru';
    $t = $lang === 'en'
        ? [
            'title' => 'Price request',
            'intro_a' => 'Please provide the',
            'intro_b' => 'price, availability and lead time',
            'intro_c' => 'for the following items:',
            'h_no' => 'No.',
            'h_name' => 'Item',
            'h_oem' => 'OEM / brand',
            'h_qty' => 'Qty',
            'reply' => 'Please reply to this email with prices, availability and lead times.',
            'fallback_greeting' => 'Hello,',
        ]
        : [
            'title' => 'Запрос расценки',
            'intro_a' => 'Просим дать',
            'intro_b' => 'цену, наличие и срок поставки',
            'intro_c' => 'на следующие позиции:',
            'h_no' => '№',
            'h_name' => 'Наименование',
            'h_oem' => 'Артикул / бренд',
            'h_qty' => 'Кол-во',
            'reply' => 'Ответьте, пожалуйста, на это письмо с ценами/наличием/сроками.',
            'fallback_greeting' => 'Здравствуйте!',
        ];
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $t['title'] }}</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,Arial,sans-serif;background:#f5f6f8;color:#1a1a1a">
    <div style="max-width:640px;margin:0 auto;padding:20px 14px">
        <p style="font-size:14px;margin:0 0 14px">{{ $greeting ?? $t['fallback_greeting'] }}</p>
        <p style="font-size:14px;margin:0 0 16px">
            {{ $t['intro_a'] }} <b>{{ $t['intro_b'] }}</b> {{ $t['intro_c'] }}
        </p>

        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px">
            <thead>
                <tr>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">{{ $t['h_no'] }}</th>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">{{ $t['h_name'] }}</th>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">{{ $t['h_oem'] }}</th>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:right;font-size:11px;color:#6b7280;text-transform:uppercase">{{ $t['h_qty'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $r)
                    <tr>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top">{{ $i + 1 }}</td>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;font-weight:600">{{ $r['name'] ?: '—' }}</td>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;color:#4b5563">
                            {{ trim(implode(' · ', array_filter([$r['oem'] ?? null, $r['brand'] ?? null]))) ?: '—' }}
                        </td>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;text-align:right;white-space:nowrap">
                            {{ $r['qty'] ?? '' ?: '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(trim((string) ($note ?? '')) !== '')
            <div style="background:#f0f7ff;border-left:3px solid #3b82f6;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;margin-bottom:18px;white-space:pre-line">{{ $note }}</div>
        @endif

        <p style="font-size:13px;color:#4b5563;margin:0 0 4px">{{ $t['reply'] }}</p>
    </div>
</body>
</html>
