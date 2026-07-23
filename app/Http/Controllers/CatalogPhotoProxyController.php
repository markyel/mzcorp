<?php

namespace App\Http\Controllers;

use App\Services\Catalog\CatalogPhotoCache;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Прокси для фото каталога с дисковым кэшем.
 *
 * Проблема: `catalog_items.photo_url` указывает на внешний
 * `https://mylift.ru/photo.php?id=GUID`. Каждый просмотр диалога каталога
 * = 302 redirect → реальная картинка с CDN: ~500-800мс на фото, ×20 thumb
 * = 10+ секунд waterfall. Браузер не кеширует из-за стандартных заголовков
 * внешнего сервиса.
 *
 * Решение: при первом обращении к `/img/cat/{id}` скачиваем картинку,
 * сохраняем в `storage/app/public/catalog-photos/{id}.bin` + храним
 * Content-Type рядом. На последующих запросах отдаём с диска +
 * `Cache-Control: public, max-age=2592000` (30 дней) — браузер тоже
 * кеширует.
 *
 * Безопасность: принимаем только catalog_item.id из БД, photo_url
 * берём оттуда. SSRF исключён.
 */
class CatalogPhotoProxyController extends Controller
{
    /** TTL кэша браузера в секундах (30 дней). */
    private const BROWSER_TTL = 30 * 24 * 3600;

    public function __construct(private readonly CatalogPhotoCache $cache)
    {
    }

    public function show(int $id): Response|BinaryFileResponse
    {
        // Кэш фото (скачивание + сохранение на public-диск) — в CatalogPhotoCache
        // (единый источник, переиспользуется рассылкой RFQ поставщику).
        $cached = $this->cache->ensure($id);
        if ($cached === null) {
            return $this->placeholder404();
        }

        $absPath = Storage::disk('public')->path($cached['rel']);

        return response()->file($absPath, [
            'Content-Type' => $cached['mime'],
            'Cache-Control' => 'public, max-age=' . self::BROWSER_TTL . ', immutable',
        ]);
    }

    private function placeholder404(): Response
    {
        // 1×1 transparent PNG. Браузер увидит «битую» картинку как пустоту,
        // не делает retry. Кэшируем как обычно, чтобы не дёргать каждый раз.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // Короткий TTL — вдруг внешний сервис восстановится и фото появится.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
