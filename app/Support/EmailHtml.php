<?php

namespace App\Support;

/**
 * Правка HTML письма перед показом в CRM.
 */
final class EmailHtml
{
    /** Прозрачный 1×1 GIF — подстановка вместо картинки, которой в письме нет. */
    public const MISSING_IMAGE_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Картинки со ссылкой на файл КОМПЬЮТЕРА ОТПРАВИТЕЛЯ — их нет ни у нас, ни у
     * любого получателя. Почта iPhone вставляет логотип подписи как
     * `src="file:///private/var/mobile/…/ContentImages/…"` и не прикладывает
     * сам файл (M-2026-19436, 14.10.2026): в письме — иконка битой картинки.
     * Подставляем прозрачный пиксель с той же пометкой, что у недошедших cid.
     */
    /**
     * HTML письма мы храним и показываем в UTF-8, а Outlook оставляет в нём `<meta … charset=koi8-r>`
     * (windows-1251, …) — браузер верит мете и читает UTF-8 как KOI8-R: «п╬п╩п╩п╣пЁп╦» вместо
     * «Коллеги» (Метеор Лифт, MZ2026-01418, 12.11.2026). Кодировку в мете — на utf-8.
     */
    public static function declareUtf8(string $html): string
    {
        return preg_replace_callback(
            '/<meta\b[^>]*>/i',
            fn ($m) => preg_replace('/charset\s*=\s*(["\']?)[\w.:-]+\1/i', 'charset=$1utf-8$1', $m[0]) ?? $m[0],
            $html,
        ) ?? $html;
    }

    public static function hideLocalImages(string $html): string
    {
        return preg_replace(
            '/\bsrc\s*=\s*(["\'])(?:file|x-apple-[a-z-]+|webkit-fake-url|blob):[^"\']*\1/i',
            'src="'.self::MISSING_IMAGE_PIXEL.'" data-cid-missing="1"',
            $html,
        ) ?? $html;
    }

    /**
     * `src=`/`href=` со ссылкой на вложение письма `cid:…` — в кавычках И без: Lotus Notes
     * пишет `<img src=cid:_4_165D…>` (ДМА, MZ2026-00483, 09.11.2026), а шаблон «только в
     * кавычках» такую картинку пропускал — в CRM битая иконка, хотя вложение есть.
     */
    public const CID_ATTR_PATTERN = '/(src|href)\s*=\s*(?:(["\'])cid:([^"\']+)\2|cid:([^\s"\'>]+))/i';

    /**
     * Разбор совпадения CID_ATTR_PATTERN.
     *
     * @param  array<int, string>  $m
     * @return array{0: string, 1: string, 2: string} [атрибут, кавычка, cid]
     */
    public static function cidParts(array $m): array
    {
        $quoted = ($m[2] ?? '') !== '';

        return [$m[1], $quoted ? $m[2] : '"', $quoted ? $m[3] : ($m[4] ?? '')];
    }
}
