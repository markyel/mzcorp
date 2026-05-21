<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
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
    /** Поддиректория в storage/app/public/. */
    private const CACHE_DIR = 'catalog-photos';

    /** TTL кэша браузера в секундах (30 дней). */
    private const BROWSER_TTL = 30 * 24 * 3600;

    /** Таймауты HTTP-запроса к внешнему серверу. */
    private const FETCH_TIMEOUT = 8;
    private const FETCH_CONNECT_TIMEOUT = 3;

    public function show(int $id): Response|BinaryFileResponse
    {
        // Файл уже закэширован — отдаём с диска.
        $relPath = self::CACHE_DIR . '/' . $id . '.bin';
        $relMeta = self::CACHE_DIR . '/' . $id . '.meta';

        if (Storage::disk('public')->exists($relPath)) {
            return $this->serveFromDisk($relPath, $relMeta);
        }

        // Не закэшировано — пробуем скачать.
        $cat = CatalogItem::query()
            ->where('id', $id)
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->first(['id', 'photo_url']);

        if (! $cat) {
            return $this->placeholder404();
        }

        try {
            $response = Http::timeout(self::FETCH_TIMEOUT)
                ->connectTimeout(self::FETCH_CONNECT_TIMEOUT)
                ->withHeaders([
                    'User-Agent' => 'MyLift-Image-Proxy/1.0',
                ])
                ->get((string) $cat->photo_url);

            if (! $response->successful()) {
                return $this->placeholder404();
            }

            $bytes = $response->body();
            $contentType = $response->header('Content-Type') ?: 'image/jpeg';

            // Сохраняем атомарно (через tmp + rename), чтобы конкурентные
            // запросы не получили полу-записанный файл.
            Storage::disk('public')->put($relPath, $bytes);
            Storage::disk('public')->put($relMeta, $contentType);
        } catch (\Throwable $e) {
            // Внешний сервис недоступен — отдаём placeholder, не валим UI.
            return $this->placeholder404();
        }

        return $this->serveFromDisk($relPath, $relMeta);
    }

    private function serveFromDisk(string $relPath, string $relMeta): BinaryFileResponse
    {
        $absPath = Storage::disk('public')->path($relPath);
        $contentType = 'image/jpeg';
        if (Storage::disk('public')->exists($relMeta)) {
            $contentType = trim(Storage::disk('public')->get($relMeta)) ?: 'image/jpeg';
        }

        return response()->file($absPath, [
            'Content-Type' => $contentType,
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
