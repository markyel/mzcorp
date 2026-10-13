<?php

namespace App\Services\Marketing;

use App\Services\AI\OpenAIChatService;
use App\Services\AI\OpenAIImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Сцена для обложки ассортиментного поста — из НАШИХ деталей, а не выдуманных.
 *
 * Заказчик (13.10.2026): обложки, нарисованные по названиям позиций, вызывают
 * у специалистов сомнения, а коллаж из каталожных фото некрасив (разные фоны,
 * водяные знаки, снимки на объекте). Поэтому:
 *  1. фото позиций поста отбираются моделью: одна деталь целиком на
 *     однотонном фоне; снимки на оборудовании отсеиваются — по ним модель
 *     дорисовывает несуществующее (КВШ на лебёдке превратился в шкив с
 *     кронштейном);
 *  2. каждая деталь перерисовывается по своему фото в чистый студийный вид
 *     (gpt-image-1 edit, input_fidelity=high) — результат хранится по фото и
 *     переиспользуется в следующих постах;
 *  3. перерисованные детали — образцы для одной общей сцены: единые фон,
 *     ракурс и свет. Из сырых фото сразу сцена не выходит: модель обрезала
 *     плату и исказила КВШ; из очищенных деталей — держит форму.
 */
class CatalogCoverSceneService
{
    /** Деталей на обложке: больше — мелко. */
    public const MAX_PARTS = 4;

    /** Меньше — сцены нет, обложка без деталей. */
    public const MIN_PARTS = 2;

    /** Сколько фото показываем модели-отборщику: позиций в посте до 6–8. */
    private const MAX_CANDIDATES = 8;

    private const PARTS_DIR = 'media-covers/parts';

    private const SKU_RE = '/\bM\d{4,6}\b/u';

    private const KEEP = 'Keep every object exactly as in its reference photo: the same shape, proportions, number of parts, colours, materials, connectors, pins, spokes and details. '
        .'Do not add, remove, merge or invent components. No text, letters, digits, logos or watermarks anywhere.';

    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly OpenAIImageService $images,
    ) {
    }

    /**
     * Сцена ассортиментного поста (байты JPEG 1536×1024) или null — годных
     * деталей меньше двух.
     */
    public function scene(string $body): ?string
    {
        if (! preg_match_all(self::SKU_RE, $body, $m)) {
            return null;
        }
        $skus = array_values(array_unique($m[0]));
        $urls = DB::table('catalog_items')
            ->whereIn('sku', $skus)
            ->whereNotNull('photo_url')->where('photo_url', '!=', '')
            ->orderByDesc('is_active')
            ->get(['sku', 'photo_url'])
            ->unique('sku')
            ->pluck('photo_url', 'sku');

        $ordered = [];
        foreach ($skus as $sku) {
            if (isset($urls[$sku])) {
                $ordered[$sku] = (string) $urls[$sku];
            }
        }

        return $this->sceneOf($ordered, self::MIN_PARTS);
    }

    /**
     * Сцена из одной детали — к совету по категории («Как оформить заявку на
     * ролик»): самая спрашиваемая позиция категории, перерисованная по фото,
     * вместо выдуманной. null — фото не годится (снимок на объекте и т.п.).
     */
    public function sceneForPhoto(string $photoUrl): ?string
    {
        // Артикул — только для имени файла перерисовки: та же деталь в посте
        // каталога возьмёт её из хранилища.
        $sku = (string) (DB::table('catalog_items')->where('photo_url', $photoUrl)->value('sku') ?? 'part');

        return $this->sceneOf([$sku => $photoUrl], 1);
    }

    /** @param  array<string, string>  $urls  sku => фото, в нужном порядке */
    private function sceneOf(array $urls, int $min): ?string
    {
        $photos = $this->studioPhotos($this->download($urls), $min);
        if (count($photos) < $min) {
            return null;
        }

        $parts = $this->redrawn($photos);
        if (count($parts) < $min) {
            return null;
        }

        $n = count($parts);
        $layout = $n === 1
            ? 'One studio product photograph with exactly this elevator spare part from the reference image, '
                .'large, centered horizontally, entirely inside the upper 60% of the frame; '
            : 'One studio product photograph with exactly these '.$n.' elevator spare parts from the '.$n.' reference images, '
                .'standing side by side in a single horizontal row, evenly spaced, all of them entirely inside the upper 60% of the frame; ';

        return $this->images->edit(
            array_values($parts),
            $layout.'the lower 40% of the frame is an empty continuous surface. One continuous light grey-blue seamless surface (#E3E9EF), '
                .'all objects seen from the same slightly elevated three-quarter angle, one soft key light from the upper left, '
                .'consistent soft contact shadows, realistic relative sizes. '.self::KEEP,
        );
    }

    /**
     * Скачать фото: [sku => [url, bytes]], не больше MAX_CANDIDATES.
     *
     * @param  array<string, string>  $urls
     * @return array<string, array{url: string, bytes: string}>
     */
    private function download(array $urls): array
    {
        $out = [];
        foreach ($urls as $sku => $url) {
            if (count($out) >= self::MAX_CANDIDATES) {
                break;
            }
            try {
                $r = Http::timeout(20)->get($url);
                $bytes = $r->successful() ? (string) $r->body() : '';
            } catch (\Throwable) {
                $bytes = '';
            }
            if ($bytes !== '' && @getimagesizefromstring($bytes) !== false) {
                $out[$sku] = ['url' => $url, 'bytes' => $bytes];
            }
        }

        return $out;
    }

    /**
     * Только снимки одной детали целиком на однотонном фоне — по ним модель
     * перерисовывает без выдумок.
     *
     * @param  array<string, array{url: string, bytes: string}>  $photos
     * @return array<string, array{url: string, bytes: string}>
     */
    private function studioPhotos(array $photos, int $min): array
    {
        if (count($photos) < $min) {
            return [];
        }
        $skus = array_keys($photos);

        try {
            $res = $this->chat->analyzeMultipleImages(
                'These are catalog photos of elevator spare parts, numbered from 1 in the order given. '
                    .'For each photo decide if it is a clean product shot: ONE spare part is the main subject, it is visible entirely, '
                    .'on a plain or studio background (a watermark is fine). Reject photos of parts installed on equipment, '
                    .'close-ups where the part is cut off, several different objects, drawings, documents, labels or packaging. '
                    .'Answer JSON {"suitable": [numbers]}.',
                array_map(fn ($p) => 'data:'.self::mime($p['bytes']).';base64,'.base64_encode($p['bytes']), array_values($photos)),
                (string) config('services.openai.media_cover.scene_model', 'gpt-4o-mini'),
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0],
            );
        } catch (\Throwable $e) {
            Log::warning('CatalogCoverScene: photo check failed', ['error' => $e->getMessage()]);

            return [];
        }

        $ok = [];
        foreach ((array) (json_decode((string) ($res['content'] ?? ''), true)['suitable'] ?? []) as $n) {
            $sku = $skus[(int) $n - 1] ?? null;
            if ($sku !== null) {
                $ok[$sku] = $photos[$sku];
            }
        }
        // Порядок — как в тексте поста.
        $ok = array_intersect_key($photos, $ok);

        return array_slice($ok, 0, self::MAX_PARTS, true);
    }

    /**
     * Перерисованные детали [sku => байты]: готовые — из хранилища, новые —
     * параллельно. Ключ хранения — фото, а не артикул: сменилось фото —
     * перерисуем заново.
     *
     * @param  array<string, array{url: string, bytes: string}>  $photos
     * @return array<string, string>
     */
    private function redrawn(array $photos): array
    {
        $disk = Storage::disk('public');
        $out = [];
        $todo = [];
        foreach ($photos as $sku => $p) {
            $path = self::PARTS_DIR.'/'.$sku.'-'.substr(sha1($p['url']), 0, 12).'.jpg';
            if ($disk->exists($path)) {
                $out[$sku] = (string) $disk->get($path);
            } else {
                $todo[$sku] = $p['bytes'];
            }
        }

        $fresh = $this->images->editMany(
            $todo,
            'Clean studio product photograph of exactly this elevator spare part from the reference photo, the whole part visible, centered, '
                .'on a plain light grey-blue background (#E3E9EF), soft diffused light, soft contact shadow. '.self::KEEP,
        );
        foreach ($fresh as $sku => $bytes) {
            if ($bytes === null) {
                Log::warning('CatalogCoverScene: part redraw failed', ['sku' => $sku]);

                continue;
            }
            $disk->put(self::PARTS_DIR.'/'.$sku.'-'.substr(sha1($photos[$sku]['url']), 0, 12).'.jpg', $bytes);
            $out[$sku] = $bytes;
        }

        // Порядок — как в тексте поста.
        return array_intersect_key(array_replace($photos, $out), $out);
    }

    private static function mime(string $bytes): string
    {
        return str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
    }
}
