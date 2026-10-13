<?php

namespace App\Services\Marketing;

use App\Services\AI\OpenAIChatService;
use App\Services\AI\OpenAIImageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Анонсовая обложка поста — одна сгенерированная картинка, своя для каждого
 * поста и по его содержанию.
 *
 * Заказчик (12.10.2026): к постам — обложка, сначала к ассортиментным, затем ко
 * всем типам. 13.10: детали, нарисованные по названиям позиций, вызывали у
 * специалистов сомнения — ассортиментный пост теперь получает сцену из НАШИХ
 * деталей, перерисованных по каталожным фото (CatalogCoverSceneService). Нет
 * хотя бы двух годных фото — сцена без деталей крупным планом (склад, лифты),
 * чтобы не выдумывать конкретную деталь. Совет по категории — та же сцена из
 * одной детали: самой спрашиваемой позиции категории. Новость, дайджест и
 * совет без годного фото — сцена по смыслу текста, только архитектура.
 * Текст на картинку модель не пишет (кириллицу она путает) — заголовок
 * накладываем сами шрифтом PT Sans.
 */
class MediaCoverService
{
    /** Ассортиментные подборки: обложка из деталей поста. */
    public const SOURCES = ['catalog_new', 'catalog_price', 'stock_arrivals', 'weekly_roundup'];

    private const DIR = 'media-covers';

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly OpenAIImageService $images,
        private readonly CatalogCoverSceneService $catalogScene,
        private readonly MediaDataService $data,
    ) {
    }

    /** Обложки включены: ко всем постам площадок, куда публикуем сами. */
    public static function enabled(): bool
    {
        return (bool) config('services.openai.media_cover.enabled', true);
    }

    /**
     * @return array{ok: bool, url: ?string, message: string}
     */
    public function make(string $title, string $body, ?string $source, ?string $subjectKey = null): array
    {
        if (trim($title.$body) === '') {
            return ['ok' => false, 'url' => null, 'message' => 'пустой пост — не из чего собрать обложку'];
        }

        try {
            $catalog = in_array($source, self::SOURCES, true);
            $scene = null;
            if ($catalog) {
                $scene = $this->catalogScene->scene($body);
            } elseif ($source === 'request_tips' && ($photo = $this->data->tipPhotoUrl($subjectKey)) !== null) {
                $scene = $this->catalogScene->sceneForPhoto($photo);
            }
            $jpeg = $scene !== null
                ? $this->compose($scene, $title, fitObjects: true)
                : $this->compose($this->images->generate($this->scenePrompt($catalog
                    ? $this->catalogBackdrop($source)
                    : $this->scene($title, $body, $source))), $title);
        } catch (\Throwable $e) {
            Log::warning('MediaCoverService: cover failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'url' => null, 'message' => $e->getMessage()];
        }

        $name = Str::uuid()->toString().'.jpg';
        Storage::disk('public')->put(self::DIR.'/'.$name, $jpeg);

        return ['ok' => true, 'url' => route('media.cover', ['name' => $name]), 'message' => ''];
    }

    /** Удалить файл нашей обложки (при замене в черновике). Чужие адреса не трогаем. */
    public function forget(string $url): void
    {
        $prefix = rtrim(route('media.cover', ['name' => 'x']), 'x');
        if (str_starts_with($url, $prefix)) {
            $name = basename(substr($url, strlen($prefix)));
            if (self::validName($name)) {
                Storage::disk('public')->delete(self::DIR.'/'.$name);
            }
        }
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9-]{36}\.jpg$/', $name);
    }

    public static function path(string $name): string
    {
        return Storage::disk('public')->path(self::DIR.'/'.$name);
    }

    /**
     * Ассортиментный пост без годных фото деталей: сцена без конкретной детали
     * крупным планом — выдуманная деталь хуже, чем никакой.
     */
    private function catalogBackdrop(?string $source): string
    {
        // Только сюжеты, где нечего додумать: 13.10 машинное помещение дало
        // выдуманную лебёдку — специалист такое замечает сразу.
        return match ($source) {
            'stock_arrivals' => 'A tidy modern spare parts warehouse: neat metal shelving with closed plain cardboard boxes, soft daylight, shallow depth of field.',
            'catalog_price' => 'A bright modern building lobby with closed brushed steel elevator doors seen from a distance, calm and clean, soft daylight.',
            default => 'A bright modern glass atrium of a business centre with escalators seen from a distance in a wide shot, soft daylight.',
        };
    }

    private function scenePrompt(string $scene): string
    {
        return 'Editorial illustration for a social media post of an elevator and escalator spare parts supplier. '
            .'Scene: '.$scene.' '
            .'Photorealistic, natural soft light, clean modern look, calm colours, shallow depth of field. '
            .'People only if the scene needs them: seen from behind or at a distance, no identifiable faces. '
            .self::RESTRICTIONS;
    }

    // Запреты на технику здесь не перечисляем: список «no winches, sheaves…»
    // модель картинок прочла как подсказку и поставила на склад шестерни
    // (13.10). Описываем, ЧТО в кадре, — запреты только в промпте сцены (чат).
    private const RESTRICTIONS = 'Strictly no text, letters, digits, logos, brand names, labels, stickers, signs, QR codes or markings anywhere in the image. '
        .'The frame contains only architecture, interiors, shelves with closed plain boxes and people seen from behind; '
        .'elevators and escalators appear only as part of the building, seen whole from a distance. '
        .'Keep the bottom third of the frame calm and uncluttered for a caption.';

    /**
     * Сцена по смыслу поста без артикулов — только архитектура: здания, холлы
     * с закрытыми дверями лифтов издали, атриумы, эскалаторы общим планом,
     * склад. Технику крупным планом модель додумывает (13.10: панель вызова с
     * выдуманными символами, ролик с рулеткой) — её нет. Модель описывает
     * картинку по-английски, без брендов и надписей.
     */
    private function scene(string $title, string $body, ?string $source): string
    {
        $kind = match ($source) {
            'request_tips' => 'a practical tip for customers on how to request a spare part',
            'industry_digest' => 'a digest of elevator industry news',
            'weekly_roundup' => 'a weekly review of new catalog items, price drops and stock arrivals',
            'news' => 'company news',
            default => 'a post',
        };

        $res = $this->openai->chat([
            ['role' => 'system', 'content' => 'You design a cover picture for a Russian social media post of an elevator and escalator spare parts supplier. '
                .'Read the post and describe ONE concrete photorealistic scene that matches its mood and subject (2 sentences, English). '
                .'Use architecture only: modern buildings, bright lobbies with closed elevator doors seen from a distance, glass atriums, escalators in a wide shot, tidy warehouses with plain boxes. '
                .'Never show close-ups of spare parts, machinery, machine rooms, control panels, call buttons, displays, tools or measuring tapes: '
                .'an invented technical detail makes specialists distrust the post. '
                .'No text, signs, logos, brands, screens with text, documents with text, recognisable landmarks or identifiable faces. '
                .'Answer JSON {"scene": "..."}.'],
            ['role' => 'user', 'content' => 'Type: '.$kind."\nTitle: ".$title."\n\n".mb_substr($body, 0, 3000)],
        ], (string) config('services.openai.media_cover.scene_model', 'gpt-4o-mini'), ['response_format' => ['type' => 'json_object'], 'temperature' => 0.4]);

        $scene = trim((string) (json_decode((string) ($res['content'] ?? ''), true)['scene'] ?? ''));
        if ($scene === '') {
            throw new \RuntimeException('не удалось придумать сцену для обложки');
        }

        return $scene;
    }

    /**
     * Обложка: иллюстрация на весь кадр, внизу полупрозрачная плашка «матовое
     * стекло» с заголовком (PT Sans Bold, красный акцент) и подписью myzip.ru.
     * Градиентов нет — решение заказчика 13.10.2026 (вариант «A»).
     *
     * $fitObjects — сцена из деталей на однотонном фоне: детали находим по
     * отличию от фона и кадрируем крупно — сверху небольшой отступ, низ чуть
     * заходит под плашку, крайние детали могут быть слегка подрезаны.
     */
    public function compose(string $image, string $title, bool $fitObjects = false): string
    {
        $src = @imagecreatefromstring($image);
        if ($src === false) {
            throw new \RuntimeException('картинка не читается');
        }
        $w = self::W;
        $h = self::H;
        // Эмодзи в PT Sans нет — вместо них были бы квадраты.
        $title = trim((string) preg_replace(['/[\p{So}\p{Sk}\p{Cs}\x{FE0F}\x{200D}]+/u', '/\s+/u'], ['', ' '], $title));

        $bold = resource_path('fonts/PTSans-Bold.ttf');
        $regular = resource_path('fonts/PTSans-Regular.ttf');
        $margin = (int) round($w * 0.06);
        $size = $h * 0.07;
        $lines = $this->wrap($title, $bold, $size, $w - 2 * $margin - 24);
        while (count($lines) > 2 && $size > $h * 0.045) {
            $size *= 0.9;
            $lines = $this->wrap($title, $bold, $size, $w - 2 * $margin - 24);
        }
        $lines = array_slice($lines, 0, 2);
        $lineH = (int) round($size * 1.25);
        $subSize = $h * 0.028;
        // Верх букв первой строки и низ подписи — от верха строки: плашка
        // охватывает текст с равными полями сверху и снизу.
        $capOff = (int) round($lineH - $size * 0.25 + imagettfbbox($size, 0, $bold, 'СН')[7]);
        $subBase = $lineH * count($lines) + (int) round($subSize * 1.9);
        $subBottom = $subBase + (int) imagettfbbox($subSize, 0, $regular, 'зу')[1];
        $pad = (int) round($h * 0.05);
        $plateH = $subBottom - $capOff + 2 * $pad;
        $plateTop = $h - $plateH;

        $img = imagecreatetruecolor($w, $h);
        [$sx, $sy, $sw, $sh] = $fitObjects
            ? $this->objectsFrame($src, $plateTop)
            : self::coverFrame(imagesx($src), imagesy($src), $w, $h);
        imagecopyresampled($img, $src, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
        imagedestroy($src);

        // Матовое стекло: размытая подложка + полупрозрачный тёмный слой.
        $glass = imagecreatetruecolor($w, $plateH);
        imagecopy($glass, $img, 0, 0, 0, $plateTop, $w, $plateH);
        for ($i = 0; $i < 25; $i++) {
            imagefilter($glass, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopy($img, $glass, 0, $plateTop, 0, 0, $w, $plateH);
        imagedestroy($glass);
        imagefilledrectangle($img, 0, $plateTop, $w, $h, imagecolorallocatealpha($img, 14, 17, 22, 62));

        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 25);
        $red = imagecolorallocate($img, 0xD3, 0x20, 0x27);
        $top = $plateTop + $pad - $capOff;
        $bottom = $top + $lineH * count($lines);
        imagefilledrectangle($img, $margin, $top + (int) round($size * 0.15), $margin + 9, $bottom - (int) round($size * 0.2), $red);
        foreach ($lines as $i => $line) {
            imagettftext($img, $size, 0, $margin + 24, $top + $lineH * ($i + 1) - (int) round($size * 0.25), $white, $bold, $line);
        }
        imagettftext($img, $subSize, 0, $margin + 24, $top + $subBase, $soft, $regular, 'myzip.ru · запчасти для лифтов и эскалаторов');

        ob_start();
        imagejpeg($img, null, 88);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Размер обложки. */
    private const W = 1536;

    private const H = 1024;

    /**
     * Кадр сцены с деталями [x, y, ширина, высота] в координатах исходника.
     * Детали — пиксели, заметно отличные от фона той же строки у левого края.
     *
     * @return array{int, int, int, int}
     */
    private function objectsFrame(\GdImage $src, int $plateTop): array
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $x0 = $sw;
        $y0 = $sh;
        $x1 = 0;
        $y1 = 0;
        for ($y = 0; $y < $sh; $y += 2) {
            $ref = imagecolorat($src, 4, $y);
            for ($x = 0; $x < $sw; $x += 2) {
                $c = imagecolorat($src, $x, $y);
                $d = abs((($c >> 16) & 255) - (($ref >> 16) & 255)) + abs((($c >> 8) & 255) - (($ref >> 8) & 255)) + abs(($c & 255) - ($ref & 255));
                if ($d > 60) {
                    $x0 = min($x0, $x);
                    $x1 = max($x1, $x);
                    $y0 = min($y0, $y);
                    $y1 = max($y1, $y);
                }
            }
        }
        if ($x1 - $x0 < $sw * 0.2 || $y1 - $y0 < $sh * 0.1) {
            return self::coverFrame($sw, $sh, self::W, self::H);
        }

        $bw = $x1 - $x0;
        $bh = $y1 - $y0;
        $topGap = (int) round(self::H * 0.05);
        $under = (int) round((self::H - $plateTop) * 0.12);
        // Крупно, но по ширине подрезаем не больше ~4% с каждой стороны.
        $s = min(($plateTop + $under - $topGap) / $bh, (self::W * 1.08) / $bw);
        $topGap = (int) max($topGap, ($plateTop + $under) - $bh * $s);

        $cw = (int) round(self::W / $s);
        $ch = (int) round(self::H / $s);
        $cx = (int) round(($x0 + $x1) / 2 - $cw / 2);
        $cy = (int) round($y0 - $topGap / $s);
        // Кадр не выходит за исходник — сдвигаем внутрь.
        $cx = max(0, min($cx, $sw - $cw));
        $cy = max(0, min($cy, $sh - $ch));

        return [$cx, $cy, min($cw, $sw), min($ch, $sh)];
    }

    /**
     * Заполнить кадр целиком с сохранением пропорций (лишнее по краям режем).
     *
     * @return array{int, int, int, int}
     */
    private static function coverFrame(int $sw, int $sh, int $w, int $h): array
    {
        $s = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $s);
        $ch = (int) round($h / $s);

        return [(int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $cw, $ch];
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, float $size, int $maxWidth): array
    {
        $lines = [];
        $line = '';
        // Короткое слово («на», «за», «и») не оставляем в конце строки — клеим
        // к следующему, как неразрывный пробел в вёрстке.
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $glued = [];
        foreach ($words as $word) {
            $prev = end($glued);
            if ($prev !== false && mb_strlen($prev) <= 2 && preg_match('/^\p{L}+$/u', $prev) && ! str_contains($prev, ' ')) {
                $glued[count($glued) - 1] = $prev.' '.$word;
            } else {
                $glued[] = $word;
            }
        }
        foreach ($glued as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && ($box[2] - $box[0]) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
