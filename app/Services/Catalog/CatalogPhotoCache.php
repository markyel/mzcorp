<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Кэш полноразмерных фото каталога на диске `public`
 * (`catalog-photos/{id}.bin` + `.meta` с content-type). Единый источник:
 *   - CatalogPhotoProxyController — отдаёт картинку в UI (route catalog.photo);
 *   - рассылка RFQ поставщику — прикрепляет фото позиции к письму.
 *
 * Фото — внешний URL (CatalogItem::photo_url, из 1С/источника каталога),
 * тянем один раз и кладём на диск. TTL нет — фото позиции статично; при смене
 * photo_url кэш чистится тем, кто обновляет каталог (или вручную).
 */
class CatalogPhotoCache
{
    /** Поддиректория в storage/app/public/. */
    public const CACHE_DIR = 'catalog-photos';

    private const FETCH_TIMEOUT = 8;
    private const FETCH_CONNECT_TIMEOUT = 3;

    /**
     * Убедиться, что фото позиции закэшировано на public-диске. Возвращает
     * относительный путь .bin + content-type, либо null (нет photo_url /
     * внешний сервис недоступен). Идемпотентно.
     *
     * @return array{rel: string, mime: string}|null
     */
    public function ensure(int $catalogItemId): ?array
    {
        $rel = self::CACHE_DIR . '/' . $catalogItemId . '.bin';
        $relMeta = self::CACHE_DIR . '/' . $catalogItemId . '.meta';

        if (Storage::disk('public')->exists($rel)) {
            return ['rel' => $rel, 'mime' => $this->mimeFromMeta($relMeta)];
        }

        $cat = CatalogItem::query()
            ->where('id', $catalogItemId)
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->first(['id', 'photo_url']);
        if ($cat === null) {
            return null;
        }

        try {
            $response = Http::timeout(self::FETCH_TIMEOUT)
                ->connectTimeout(self::FETCH_CONNECT_TIMEOUT)
                ->withHeaders(['User-Agent' => 'MyLift-Image-Proxy/1.0'])
                ->get((string) $cat->photo_url);

            if (! $response->successful()) {
                return null;
            }

            $bytes = $response->body();
            $contentType = $response->header('Content-Type') ?: 'image/jpeg';

            Storage::disk('public')->put($rel, $bytes);
            Storage::disk('public')->put($relMeta, $contentType);

            return ['rel' => $rel, 'mime' => $contentType];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Content-type из .meta, дефолт image/jpeg. */
    public function mimeFromMeta(string $relMeta): string
    {
        if (Storage::disk('public')->exists($relMeta)) {
            return trim((string) Storage::disk('public')->get($relMeta)) ?: 'image/jpeg';
        }

        return 'image/jpeg';
    }
}
