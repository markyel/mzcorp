<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Запрос расценки</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,Arial,sans-serif;background:#f5f6f8;color:#1a1a1a">
    <div style="max-width:640px;margin:0 auto;padding:20px 14px">
        <p style="font-size:14px;margin:0 0 14px">Здравствуйте!</p>
        <p style="font-size:14px;margin:0 0 16px">
            Просим дать <b>цену, наличие и срок поставки</b> на следующие позиции:
        </p>

        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px">
            <thead>
                <tr>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">№</th>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">Наименование</th>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">Артикул / бренд</th>
                    <th style="background:#f0f1f4;padding:8px 10px;text-align:right;font-size:11px;color:#6b7280;text-transform:uppercase">Кол-во</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $i => $it)
                    <tr>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top">{{ $i + 1 }}</td>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;font-weight:600">{{ $it->parsed_name ?: '—' }}</td>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;color:#4b5563">
                            {{ trim(implode(' · ', array_filter([$it->parsed_article, $it->parsed_brand]))) ?: '—' }}
                        </td>
                        <td style="padding:9px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;text-align:right;white-space:nowrap">
                            {{ $it->parsed_qty ? trim($it->parsed_qty . ' ' . ($it->parsed_unit ?: 'шт.')) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(trim((string) ($note ?? '')) !== '')
            <div style="background:#f0f7ff;border-left:3px solid #3b82f6;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;margin-bottom:18px;white-space:pre-line">{{ $note }}</div>
        @endif

        <p style="font-size:13px;color:#4b5563;margin:0 0 4px">
            Ответьте, пожалуйста, на это письмо с ценами/наличием/сроками.
        </p>
        <p style="font-size:12px;color:#9ca3af;margin:16px 0 0">
            Заявка № {{ $request->internal_code }}
        </p>
    </div>
</body>
</html>
