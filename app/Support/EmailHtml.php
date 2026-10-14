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
    public static function hideLocalImages(string $html): string
    {
        return preg_replace(
            '/\bsrc\s*=\s*(["\'])(?:file|x-apple-[a-z-]+|webkit-fake-url|blob):[^"\']*\1/i',
            'src="'.self::MISSING_IMAGE_PIXEL.'" data-cid-missing="1"',
            $html,
        ) ?? $html;
    }
}
