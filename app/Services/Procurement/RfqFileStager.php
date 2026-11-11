<?php

namespace App\Services\Procurement;

use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogPhotoCache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Файлы запроса цены поставщикам: загруженные человеком и фото позиций из каталога — во
 * временную папку автора (диск local), откуда их берёт отправка. Общее для «Снабжения» и
 * «Запроса цен» раздела «QuoteParts».
 */
class RfqFileStager
{
    /**
     * @param  iterable<\Illuminate\Http\UploadedFile|null>  $uploads
     * @param  list<int>  $cids  позиции запроса — для фото из каталога
     * @return list<array{path:string, name:string, mime:string, size:int}>
     */
    public function stage(iterable $uploads, array $cids, bool $catalogPhotos, User $user): array
    {
        $files = [];
        foreach ($uploads as $tmp) {
            if ($tmp === null) {
                continue;
            }
            $name = $tmp->getClientOriginalName();
            $path = sprintf('mail/procurement-staging/%d/%s', $user->id, Str::random(10).'_'.$name);
            Storage::disk('local')->put($path, $tmp->get());
            $files[] = ['path' => $path, 'name' => $name, 'mime' => $tmp->getMimeType() ?: 'application/octet-stream', 'size' => $tmp->getSize() ?: 0];
        }
        if (! $catalogPhotos) {
            return $files;
        }

        $cache = app(CatalogPhotoCache::class);
        foreach (CatalogItem::query()->whereIn('id', $cids)->whereNotNull('photo_url')->get(['id', 'name', 'photo_url']) as $ci) {
            $cached = $cache->ensure((int) $ci->id);
            if ($cached === null) {
                continue;
            }
            $bytes = Storage::disk('public')->get($cached['rel']);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            $ext = str_contains($cached['mime'], 'png') ? 'png' : (str_contains($cached['mime'], 'webp') ? 'webp' : 'jpg');
            $base = Str::slug(mb_substr((string) ($ci->name ?: 'foto'), 0, 40)) ?: 'foto';
            $name = 'foto_'.$base.'.'.$ext;
            $path = sprintf('mail/procurement-staging/%d/%s', $user->id, Str::random(10).'_'.$name);
            Storage::disk('local')->put($path, $bytes);
            $files[] = ['path' => $path, 'name' => $name, 'mime' => $cached['mime'], 'size' => strlen($bytes)];
        }

        return $files;
    }
}
