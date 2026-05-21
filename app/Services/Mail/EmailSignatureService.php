<?php

namespace App\Services\Mail;

use App\Models\User;

/**
 * Email Signature v2 (2026-05-21).
 *
 * Сборка подписи для исходящих писем менеджера. Используется ВСЕМИ
 * outbound-сообщениями (replies клиенту, КП, уточнения, любые письма
 * из ComposeForm). Вставка происходит в OutgoingMailMimeBuilder
 * ::composeFinalBody().
 *
 * Источники:
 *  - Общая часть (компания, юр.лицо, ЭДО, info@, общие телефоны,
 *    websites, логотип) — из config('services.company') и
 *    config('services.company.signature').
 *  - Персональная часть — поля User'а: name, name_en, email,
 *    phone (офисный), phone_extension (доб.), mobile_phone.
 *
 * Override: если User.email_signature заполнен (legacy plain-text)
 * — используем его как-есть, шаблон не применяется. Это позволяет
 * менеджерам с нестандартной подписью оставить как было.
 */
class EmailSignatureService
{
    public function __construct() {}

    /**
     * Кэш data-URI логотипа на время request'а — чтобы не читать файл
     * по нескольку раз при отправке батча писем.
     */
    private ?string $logoDataUriCache = null;

    /**
     * Рендер подписи в plain + html для конкретного менеджера.
     *
     * @return array{plain: string, html: string}
     */
    public function render(?User $user): array
    {
        if (! $user) {
            return ['plain' => '', 'html' => ''];
        }

        // Legacy override: если менеджер заполнил произвольный текст —
        // используем его, шаблон не применяем.
        $legacy = trim((string) ($user->email_signature ?? ''));
        if ($legacy !== '') {
            return $this->legacyTextSignature($legacy);
        }

        return [
            'plain' => $this->renderPlain($user),
            'html' => $this->renderHtml($user),
        ];
    }

    /**
     * Старая plain-text подпись (User.email_signature, заполняется
     * вручную). Оборачиваем стандартным `-- ` разделителем для plain
     * и тусклым параграфом для HTML.
     *
     * @return array{plain: string, html: string}
     */
    private function legacyTextSignature(string $raw): array
    {
        $plain = "\n-- \n".$raw;
        $html = '<p style="color:#666;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;font-size:13px;line-height:1.5;">-- <br>'
            .nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'))
            .'</p>';
        return ['html' => $html, 'plain' => $plain];
    }

    /**
     * Plain-text подпись по шаблону. RFC 5322 формат с `-- ` разделителем
     * — почтовые клиенты автоматически сворачивают всё что после.
     */
    private function renderPlain(User $user): string
    {
        $sig = (array) config('services.company.signature', []);
        $company = (array) config('services.company', []);

        $lines = [];
        $lines[] = '-- ';
        $lines[] = 'С уважением / With best regards,';

        $nameRu = trim((string) ($user->name ?? ''));
        $nameEn = trim((string) ($user->name_en ?? ''));
        $nameLine = $nameRu !== '' ? $nameRu : '(имя)';
        if ($nameEn !== '') {
            $nameLine .= ' / '.$nameEn;
        }
        $lines[] = $nameLine;
        $lines[] = '';

        if (! empty($sig['tagline_ru'])) {
            $lines[] = (string) $sig['tagline_ru'];
        }
        if (! empty($company['legal_name'])) {
            $lines[] = (string) $company['legal_name'];
        }

        // Офисный телефон + доб. номер менеджера.
        $officePhone = (string) ($sig['office_phone'] ?? $company['brand_phone'] ?? '');
        $ext = trim((string) ($user->phone_extension ?? ''));
        if ($officePhone !== '') {
            $line = 'тел: '.$officePhone;
            if ($ext !== '') {
                $line .= ' доб. '.$ext;
            }
            $lines[] = $line;
        }

        $mobile = trim((string) ($user->mobile_phone ?? ''));
        if ($mobile !== '') {
            $lines[] = 'моб/Telegram: '.$mobile;
        }

        if (! empty($sig['free_phone'])) {
            $lines[] = 'тел: '.(string) $sig['free_phone'];
        }

        $personalEmail = trim((string) ($user->email ?? ''));
        if ($personalEmail !== '') {
            $lines[] = 'e-mail: '.$personalEmail;
        }
        if (! empty($sig['general_email'])) {
            $lines[] = 'e-mail: '.(string) $sig['general_email'];
        }
        if (! empty($sig['websites']) && is_array($sig['websites'])) {
            $lines[] = implode(' ', array_map(fn ($w) => 'www.'.ltrim((string) $w, '/'), $sig['websites']));
        }

        $lines[] = '';
        if (! empty($sig['tagline_en'])) {
            $lines[] = (string) $sig['tagline_en'];
        }

        // ЭДО внизу — нужно бухгалтерии для счетов, не глаз клиенту.
        if (! empty($company['edo_id'])) {
            $lines[] = '';
            $lines[] = 'ЭДО (Диадок): '.(string) $company['edo_id'];
        }

        return "\n".implode("\n", $lines);
    }

    /**
     * HTML-подпись с логотипом, цветами и нормальной типографикой.
     * Стиль вдохновлён design/uploads/08-email-signature.html (Вариант 1
     * с лого слева). Использует только inline-CSS и table-layout —
     * email-клиенты строги к внешним стилям и flex/grid.
     */
    private function renderHtml(User $user): string
    {
        $sig = (array) config('services.company.signature', []);
        $company = (array) config('services.company', []);
        $brandColor = (string) ($sig['brand_color'] ?? '#D32027');

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $nameRu = trim((string) ($user->name ?? ''));
        $nameEn = trim((string) ($user->name_en ?? ''));
        $personalEmail = trim((string) ($user->email ?? ''));
        $officePhone = (string) ($sig['office_phone'] ?? $company['brand_phone'] ?? '');
        $ext = trim((string) ($user->phone_extension ?? ''));
        $mobile = trim((string) ($user->mobile_phone ?? ''));
        $freePhone = (string) ($sig['free_phone'] ?? '');
        $generalEmail = (string) ($sig['general_email'] ?? '');
        $taglineRu = (string) ($sig['tagline_ru'] ?? '');
        $taglineEn = (string) ($sig['tagline_en'] ?? '');
        $legalName = (string) ($company['legal_name'] ?? '');
        $edoId = (string) ($company['edo_id'] ?? '');
        $logoUrl = $this->resolveLogoSrc((string) ($sig['logo_url'] ?? ''));
        $websites = is_array($sig['websites'] ?? null) ? $sig['websites'] : [];

        $rows = [];

        // Строка телефонов (только заполненные).
        $phoneRows = [];
        if ($officePhone !== '') {
            $phoneRows[] = $this->phoneRow($e, 'тел.', $officePhone, $ext !== '' ? 'доб. '.$e($ext) : null);
        }
        if ($mobile !== '') {
            $phoneRows[] = $this->phoneRow($e, 'моб.', $mobile, '· Telegram');
        }
        if ($freePhone !== '') {
            $phoneRows[] = $this->phoneRow($e, '8-800', $freePhone, null);
        }
        $phonesBlock = '';
        if (! empty($phoneRows)) {
            $phonesBlock = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
                .'style="border-collapse:collapse;font:400 12.5px/1.55 -apple-system,Segoe UI,Arial,sans-serif;color:#0f1419;margin-top:4px">'
                .implode('', $phoneRows)
                .'</table>';
        }

        // Email + сайты.
        $emailParts = [];
        if ($personalEmail !== '') {
            $emailParts[] = '<a href="mailto:'.$e($personalEmail).'" style="color:#0f1419;text-decoration:none;font-weight:500">'.$e($personalEmail).'</a>';
        }
        if ($generalEmail !== '') {
            $emailParts[] = '<a href="mailto:'.$e($generalEmail).'" style="color:#5c6470;text-decoration:none">'.$e($generalEmail).'</a>';
        }
        $emailLine = '';
        if (! empty($emailParts)) {
            $emailLine = '<div style="font:400 12.5px/1.55 -apple-system,Segoe UI,Arial,sans-serif;color:#0f1419;margin-top:4px">'
                .'<span style="color:#5c6470">e-mail:</span> '
                .implode('<span style="color:#9aa0a8"> · </span>', $emailParts)
                .'</div>';
        }

        $siteLine = '';
        if (! empty($websites)) {
            $siteParts = [];
            foreach ($websites as $w) {
                $w = (string) $w;
                if ($w === '') {
                    continue;
                }
                $siteParts[] = '<a href="https://www.'.$e($w).'" style="color:'.$e($brandColor).';text-decoration:none;font-weight:500">'.$e($w).'</a>';
            }
            if (! empty($siteParts)) {
                $siteLine = '<div style="font:400 12.5px/1.55 -apple-system,Segoe UI,Arial,sans-serif;color:#0f1419;margin-top:2px">'
                    .'<span style="color:#5c6470">сайт:</span> '
                    .implode('<span style="color:#9aa0a8"> · </span>', $siteParts)
                    .'</div>';
            }
        }

        // EDO внизу мелким шрифтом.
        $edoBlock = '';
        if ($edoId !== '') {
            $edoBlock = '<div style="margin-top:10px;padding:6px 10px;background:#fafbfc;border:1px solid #e3e6eb;border-radius:4px;'
                .'font:400 10.5px/1.4 -apple-system,Segoe UI,Arial,sans-serif;color:#5c6470">'
                .'<span style="color:#0f1419;font-weight:600">ЭДО (Диадок)</span> <span style="color:#9aa0a8">идентификатор:</span> '
                .'<span style="font-family:Consolas,Courier New,monospace;color:#222;word-break:break-all">'.$e($edoId).'</span>'
                .'</div>';
        }

        // Логотип в левой колонке (если задан URL).
        $logoCell = '';
        if ($logoUrl !== '') {
            $logoCell = '<td valign="top" width="110" style="padding:0 16px 0 0;border-right:3px solid '.$e($brandColor).';vertical-align:top">'
                .'<img src="'.$e($logoUrl).'" alt="'.$e(($sig['tagline_ru'] ?? 'Мой ЗиП')).'" width="92" height="92" '
                .'style="display:block;width:92px;height:92px;border:0;outline:none;text-decoration:none">'
                .'</td>';
        }

        // Имя + С уважением.
        $nameHtml = $e($nameRu !== '' ? $nameRu : '(имя)');
        if ($nameEn !== '') {
            $nameHtml .= ' <span style="color:#9aa0a8;font-weight:400;font-size:13px">/ '.$e($nameEn).'</span>';
        }

        $textCellPadding = $logoUrl !== '' ? 'padding:0 0 0 16px' : 'padding:0';
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
            .'style="border-collapse:collapse;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif;font-size:13px;line-height:1.5;color:#0f1419;margin-top:18px">'
            .'<tr>'
            .$logoCell
            .'<td valign="top" style="'.$textCellPadding.';vertical-align:top">'
                .'<div style="font:600 15px/1.3 -apple-system,Segoe UI,Arial,sans-serif;color:#0f1419;margin-bottom:2px">'.$nameHtml.'</div>'
                .'<div style="font:400 12.5px/1.3 -apple-system,Segoe UI,Arial,sans-serif;color:#5c6470;margin-bottom:10px">'
                .'С уважением <span style="color:#9aa0a8">/ With best regards</span></div>'

                .($taglineRu !== ''
                    ? '<div style="font:600 13px/1.3 -apple-system,Segoe UI,Arial,sans-serif;color:'.$e($brandColor).';margin-bottom:2px">'.$e($taglineRu).'</div>'
                    : '')
                .'<div style="font:400 11.5px/1.3 -apple-system,Segoe UI,Arial,sans-serif;color:#9aa0a8;margin-bottom:8px;font-style:italic">'
                    .($taglineEn !== '' ? $e($taglineEn) : '')
                    .($legalName !== '' ? ' · '.$e($legalName) : '')
                .'</div>'

                .$phonesBlock
                .$emailLine
                .$siteLine
                .$edoBlock
            .'</td>'
            .'</tr></table>';

        return $html;
    }

    /**
     * Превращает logo_url из config в src для <img>. Если URL указывает
     * на локальный файл в public/ — встраиваем как data:image/...;base64
     * (надёжнее: email-клиенты не блокируют data-URI как внешние картинки).
     * Если внешний URL (http/https другого хоста) — оставляем как есть
     * с учётом риска блокировки.
     *
     * Возвращает пустую строку если ни локального файла, ни валидного
     * URL — тогда renderHtml() не рендерит логотип-колонку.
     */
    private function resolveLogoSrc(string $configured): string
    {
        if ($this->logoDataUriCache !== null) {
            return $this->logoDataUriCache;
        }

        // 1) Если в config есть путь, выглядящий как локальный
        //    (https://OUR-DOMAIN/assets/... или /assets/... или просто
        //    «logo-myzip-email.png»), читаем из public/.
        $candidates = [];
        if ($configured !== '') {
            // Извлекаем path из URL.
            $path = parse_url($configured, PHP_URL_PATH) ?: $configured;
            $path = ltrim($path, '/');
            if ($path !== '') {
                $candidates[] = public_path($path);
            }
        }
        // Default fallback — public/assets/logo-myzip-email.{svg,png}.
        // SVG приоритетнее: цветной герб на прозрачном; PNG-вариант
        // оказался белым (для тёмных фонов) — не виден на белом письме.
        $candidates[] = public_path('assets/logo-myzip-email.svg');
        $candidates[] = public_path('assets/logo-myzip-email.png');

        foreach ($candidates as $absPath) {
            if (! is_file($absPath) || ! is_readable($absPath)) {
                continue;
            }
            $content = @file_get_contents($absPath);
            if ($content === false || $content === '') {
                continue;
            }
            $mime = $this->detectImageMime($absPath, $content);
            if ($mime === null) {
                continue;
            }
            $b64 = base64_encode($content);
            return $this->logoDataUriCache = 'data:'.$mime.';base64,'.$b64;
        }

        // Нет локального файла — оставляем внешний URL (если был задан).
        // Это работает если файл реально доступен по https и почтовый
        // клиент не блокирует внешние картинки. Хуже data-URI, но
        // лучше чем ничего.
        return $this->logoDataUriCache = $configured;
    }

    private function detectImageMime(string $path, string $content): ?string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };
    }

    /**
     * Строка таблицы с подписью телефона (label | number | extra).
     */
    private function phoneRow(callable $e, string $label, string $number, ?string $extra): string
    {
        $href = 'tel:'.preg_replace('/[^\d+]/', '', $number);
        $extraHtml = $extra !== null ? '<span style="color:#5c6470">&nbsp;'.$extra.'</span>' : '';
        return '<tr>'
            .'<td style="color:#5c6470;padding:0 10px 1px 0;vertical-align:top;width:42px">'.$e($label).'</td>'
            .'<td style="padding:0 0 1px;vertical-align:top">'
                .'<a href="'.$e($href).'" style="color:#0f1419;text-decoration:none;font-family:Consolas,Courier New,monospace;font-size:12px">'.$e($number).'</a>'
                .$extraHtml
            .'</td>'
            .'</tr>';
    }
}
