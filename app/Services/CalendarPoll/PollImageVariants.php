<?php

namespace App\Services\CalendarPoll;

use RuntimeException;

/**
 * Копии фото для галереи опроса: миниатюра и просмотр в JPEG (GD, как VisionImageDownscaler). Поворот
 * снимка по EXIF учитывается — иначе фото с телефона легло бы набок. Оригинал хранится как загрузили.
 */
class PollImageVariants
{
    /** Гард от нехватки памяти: GD держит кадр целиком (4 байта на пиксель). */
    public const MAX_PIXELS = 80_000_000;

    private const JPEG_QUALITY = 84;

    /**
     * @return array{thumb: string, preview: string, width: int, height: int}
     *
     * @throws RuntimeException с понятной причиной (формат, размер)
     */
    public function make(string $bytes): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('На сервере нет GD — копии фото не сделать.');
        }
        $dim = @getimagesizefromstring($bytes);
        if (! is_array($dim) || empty($dim[0]) || empty($dim[1])) {
            throw new RuntimeException('Файл не читается как картинка (нужен JPG, PNG или WebP; HEIC с iPhone — сохраните в JPG).');
        }
        if ($dim[0] * $dim[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Слишком большое фото: '.$dim[0].'×'.$dim[1].' px.');
        }

        @ini_set('memory_limit', '1024M');
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new RuntimeException('Формат фото не поддерживается (нужен JPG, PNG или WebP).');
        }

        try {
            $src = $this->upright($src, $bytes);

            return [
                'thumb' => $this->jpeg($src, (int) config('services.calendar_poll.thumb_edge', 640)),
                'preview' => $this->jpeg($src, (int) config('services.calendar_poll.preview_edge', 2000)),
                'width' => imagesx($src),
                'height' => imagesy($src),
            ];
        } finally {
            imagedestroy($src);
        }
    }

    private function jpeg(\GdImage $src, int $edge): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $ratio = max($w, $h) > $edge ? $edge / max($w, $h) : 1.0;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $canvas = imagecreatetruecolor($nw, $nh);
        try {
            // Белый фон: прозрачность PNG в JPEG станет белой, а не чёрной.
            imagefilledrectangle($canvas, 0, 0, $nw, $nh, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($canvas, null, self::JPEG_QUALITY);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($canvas);
        }
    }

    /** Повернуть кадр по EXIF Orientation (3 — 180°, 6 — 90° по часовой, 8 — 90° против). */
    private function upright(\GdImage $img, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated === false) {
            return $img;
        }
        imagedestroy($img);

        return $rotated;
    }
}
