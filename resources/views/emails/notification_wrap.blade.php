<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f5f5f3;color:#1b1b18;line-height:1.55;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;">
        <tr>
            <td style="padding:24px 32px 16px;border-bottom:3px solid #D32027;">
                <div style="font-size:20px;font-weight:600;color:#D32027;letter-spacing:-0.01em;">MyZip · Лифтовые запчасти</div>
                <div style="font-size:13px;color:#727272;margin-top:2px;">mzcorp.ru · info@myzip.ru</div>
            </td>
        </tr>
        <tr>
            <td style="padding:28px 32px 24px;font-size:15px;color:#1b1b18;">
                {!! $bodyHtml !!}
            </td>
        </tr>
        <tr>
            <td style="padding:16px 32px 24px;border-top:1px solid #e6e6e1;font-size:12px;color:#727272;">
                Это автоматическое уведомление из системы MyZip CRM. Чтобы продолжить переписку, просто ответьте на это письмо — ваш ответ попадёт к ответственному менеджеру.
            </td>
        </tr>
    </table>
</body>
</html>
