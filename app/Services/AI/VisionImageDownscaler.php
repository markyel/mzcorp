<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Даунскейл ФОТО-вложений перед отправкой в OpenAI Vision. Полноразмерные
 * фото с телефона (6–7 МБ каждое) в сыром base64 пробивают лимит размера
 * запроса OpenAI → 413 Request Entity Too Large (кейс msg#26670: 4 фото
 * IMG_*.jpg = ~25 МБ → ~33 МБ base64). При detail:high OpenAI всё равно режет
 * длинную сторону до ~2048px, поэтому локальный ресайз до 2048px + JPEG q82 не
 * теряет качества для Vision, но кратно срезает байты.
 *
 * ТОЛЬКО для фото (вложения-изображения). PDF-страницы (pdftoppm 150 dpi) НЕ
 * трогаем — это путь документов, по решению заказчика остаётся как есть.
 *
 * Fail-soft: нет GD / битый кадр / ресайз не дал выигрыша → отдаём оригинал.
 */
class VisionImageDownscaler
{
    /** Длинная сторона, до которой ужимаем (потолок detail:high у OpenAI). */
    public const MAX_EDGE = 2048;

    /** Не трогаем изображения легче порога (подписи, мелкие картинки). */
    public const MAX_BYTES = 1_500_000;

    /**
     * Потолок пикселей для декода в память: truecolor ≈ w*h*4 байт + оверхед
     * декодера. 60 МП ≈ 240 МБ — выше уже риск OOM воркера, такие пропускаем
     * (отдаём оригинал; реальные фото запчастей сильно ниже).
     */
    public const MAX_PIXELS = 60_000_000;

    public const JPEG_QUALITY = 82;

    /**
     * Вернуть data-URI для фото: при необходимости ужать и переупаковать в JPEG.
     * При любой проблеме возвращает оригинал as-is (не роняет Vision-пайплайн).
     */
    public static function dataUri(string $bytes, ?string $mime, ?int $attachmentId = null): string
    {
        $mime = $mime ?: 'image/jpeg';

        // Лёгкие картинки и отсутствие GD — без обработки.
        if (strlen($bytes) <= self::MAX_BYTES || ! function_exists('imagecreatefromstring')) {
            return self::raw($bytes, $mime);
        }

        // Гард от OOM: габариты читаем из заголовка (без полного декода).
        $dim = @getimagesizefromstring($bytes);
        if (is_array($dim) && isset($dim[0], $dim[1]) && ($dim[0] * $dim[1]) > self::MAX_PIXELS) {
            return self::raw($bytes, $mime);
        }

        $src = null;
        $canvas = null;
        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return self::raw($bytes, $mime);
            }

            $w = imagesx($src);
            $h = imagesy($src);
            if ($w < 1 || $h < 1) {
                return self::raw($bytes, $mime);
            }

            $long = max($w, $h);
            $ratio = $long > self::MAX_EDGE ? self::MAX_EDGE / $long : 1.0;
            $nw = max(1, (int) round($w * $ratio));
            $nh = max(1, (int) round($h * $ratio));

            // Плоский белый фон: JPEG без альфы — прозрачность PNG станет белой,
            // а не чёрной.
            $canvas = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagejpeg($canvas, null, self::JPEG_QUALITY);
            $out = (string) ob_get_clean();

            // Если не выиграли в байтах — оставляем оригинал (редкий случай).
            if ($out === '' || strlen($out) >= strlen($bytes)) {
                return self::raw($bytes, $mime);
            }

            return 'data:image/jpeg;base64,'.base64_encode($out);
        } catch (\Throwable $e) {
            Log::warning('VisionImageDownscaler: downscale failed, using original', [
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            return self::raw($bytes, $mime);
        } finally {
            if ($src instanceof \GdImage) {
                imagedestroy($src);
            }
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }
        }
    }

    private static function raw(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
