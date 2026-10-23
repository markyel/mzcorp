<?php

namespace App\Services\Photos;

use App\Jobs\Photos\ProcessPhotoBatchJob;
use App\Models\CatalogItem;
use App\Models\PhotoBatch;
use App\Models\PhotoBatchItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Раздел «Фото»: пачка фото (файлы или zip) → очистка фона в Clipping Magic → архив с теми же именами.
 * Имя файла — M-артикул позиции: фото позиции (имя ровно артикул) ещё и сохраняется в каталог как
 * «фото без водяного знака» (CatalogItem::clean_photo_path). Тестовые результаты (с водяным знаком
 * сервиса) в каталог не пишем.
 */
class PhotoBatchService
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif', 'tif', 'tiff'];

    /** Каталог фото без водяного знака на диске local. */
    public const CATALOG_DIR = 'catalog-clean-photos';

    public function __construct(private readonly ClippingMagicClient $client) {}

    public function draftFor(User $user): PhotoBatch
    {
        return PhotoBatch::query()->where('user_id', $user->id)->where('status', PhotoBatch::STATUS_DRAFT)->latest('id')->first()
            ?? PhotoBatch::create(['user_id' => $user->id, 'status' => PhotoBatch::STATUS_DRAFT]);
    }

    /**
     * Добавить загруженный файл: фото — как есть, zip — все фото из него (папки и служебное macOS пропускаем).
     *
     * @return int сколько фото добавлено
     */
    public function addUpload(PhotoBatch $batch, UploadedFile $file): int
    {
        $name = (string) $file->getClientOriginalName();
        $ext = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            return $this->addZip($batch, $file->getRealPath());
        }
        if (! in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('«'.$name.'» — не фото и не zip.');
        }

        return $this->addImage($batch, $name, (string) file_get_contents($file->getRealPath())) ? 1 : 0;
    }

    private function addZip(PhotoBatch $batch, string $path): int
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Архив не открылся — нужен обычный zip.');
        }
        $added = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $base = basename(str_replace('\\', '/', $entry));
                if (str_ends_with($entry, '/') || str_starts_with($entry, '__MACOSX/') || str_starts_with($base, '.')
                    || ! in_array(mb_strtolower(pathinfo($base, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if ($data !== false && $this->addImage($batch, self::utf8Name($base), $data)) {
                    $added++;
                }
            }
        } finally {
            $zip->close();
        }

        return $added;
    }

    /** Имена в zip из Windows бывают в CP866 — приводим к UTF-8. */
    private static function utf8Name(string $name): string
    {
        return mb_check_encoding($name, 'UTF-8') ? $name : (string) mb_convert_encoding($name, 'UTF-8', 'CP866');
    }

    /** Фото с тем же именем в пачке заменяется новым. */
    private function addImage(PhotoBatch $batch, string $name, string $data): bool
    {
        if ($data === '') {
            return false;
        }
        $name = trim(str_replace(['/', '\\'], '_', $name));
        $path = $batch->dir().'/src/'.$name;
        Storage::disk('local')->put($path, $data);
        [$sku, $exact] = self::skuFromName($name);
        $cid = $sku ? CatalogItem::query()->where('sku', $sku)->value('id') : null;

        PhotoBatchItem::query()->updateOrCreate(['photo_batch_id' => $batch->id, 'original_name' => $name], [
            'sku' => $sku, 'sku_exact' => $exact, 'catalog_item_id' => $cid, 'source_path' => $path,
            'result_path' => null, 'status' => PhotoBatchItem::STATUS_PENDING, 'error' => null, 'saved_to_catalog' => false,
        ]);
        $batch->update(['total' => $batch->items()->count()]);

        return true;
    }

    /**
     * M-артикул из имени файла: «M12345.jpg» — ровно артикул (фото позиции); «M12345_2.jpg», «M12345 (1).png» —
     * артикул с хвостом (доп. фото, только в архив). Кириллическая «М» — как латинская.
     *
     * @return array{0: ?string, 1: bool} [артикул, имя ровно артикул]
     */
    public static function skuFromName(string $name): array
    {
        $base = mb_strtoupper(trim(pathinfo($name, PATHINFO_FILENAME)));
        $base = str_replace('М', 'M', $base);
        if (preg_match('/^M\d{3,7}$/u', $base) === 1) {
            return [$base, true];
        }
        if (preg_match('/^(M\d{3,7})(?=[\s_\-.(])/u', $base, $m) === 1) {
            return [$m[1], false];
        }

        return [null, false];
    }

    public function removeItem(PhotoBatch $batch, int $itemId): void
    {
        $item = $batch->items()->whereKey($itemId)->first();
        if ($item) {
            Storage::disk('local')->delete($item->source_path);
            $item->delete();
            $batch->update(['total' => $batch->items()->count()]);
        }
    }

    public function start(PhotoBatch $batch): void
    {
        if ($batch->status !== PhotoBatch::STATUS_DRAFT || $batch->items()->doesntExist()) {
            return;
        }
        if (! $this->client->isConfigured()) {
            throw new RuntimeException('Не задан ключ API Clipping Magic — обратитесь к администратору.');
        }
        $batch->update(['status' => PhotoBatch::STATUS_QUEUED, 'test' => $this->client->testMode(), 'total' => $batch->items()->count(), 'started_at' => now()]);
        ProcessPhotoBatchJob::dispatch($batch->id);
    }

    /** Обработать одно фото: очистка фона, результат с тем же именем, фото позиции — в каталог. */
    public function processItem(PhotoBatch $batch, PhotoBatchItem $item): void
    {
        $disk = Storage::disk('local');
        try {
            $result = $this->client->clip($disk->path($item->source_path), $item->original_name);
            $out = $batch->dir().'/out/'.$item->resultName();
            $disk->put($out, $result['body']);
            $item->fill(['result_path' => $out, 'status' => PhotoBatchItem::STATUS_DONE, 'error' => null, 'cm_image_id' => $result['id']]);

            if (! $batch->test && $item->sku_exact && $item->catalog_item_id) {
                $catalogPath = self::CATALOG_DIR.'/'.$item->sku.'.jpg';
                $disk->put($catalogPath, $result['body']);
                CatalogItem::query()->whereKey($item->catalog_item_id)->update(['clean_photo_path' => $catalogPath, 'clean_photo_at' => now()]);
                $item->saved_to_catalog = true;
            }
            $item->save();
        } catch (Throwable $e) {
            Log::warning('Photo background removal failed', ['batch' => $batch->id, 'item' => $item->id, 'file' => $item->original_name, 'error' => $e->getMessage()]);
            $item->update(['status' => PhotoBatchItem::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $this->recount($batch);
    }

    public function recount(PhotoBatch $batch): void
    {
        $counts = $batch->items()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $batch->update([
            'processed' => (int) ($counts[PhotoBatchItem::STATUS_DONE] ?? 0),
            'failed' => (int) ($counts[PhotoBatchItem::STATUS_FAILED] ?? 0),
        ]);
    }

    /** Все фото обработаны — архив результатов с теми же именами, пачка готова. */
    public function finish(PhotoBatch $batch): void
    {
        $this->recount($batch);
        $done = $batch->items()->where('status', PhotoBatchItem::STATUS_DONE)->orderBy('original_name')->get();
        if ($done->isEmpty()) {
            $batch->update(['status' => PhotoBatch::STATUS_FAILED, 'finished_at' => now()]);

            return;
        }
        $disk = Storage::disk('local');
        $archive = $batch->dir().'/Фото без фона '.$batch->id.' '.now()->format('d.m.Y').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($disk->path($archive), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось собрать архив.');
        }
        foreach ($done as $item) {
            $zip->addFile($disk->path($item->result_path), $item->resultName());
        }
        $zip->close();

        DB::transaction(fn () => $batch->update(['archive_path' => $archive, 'status' => PhotoBatch::STATUS_DONE, 'finished_at' => now()]));
    }

    /** Повторить неудавшиеся фото пачки (например, после пополнения кредитов). */
    public function retryFailed(PhotoBatch $batch): void
    {
        if ($batch->inWork() || $batch->items()->where('status', PhotoBatchItem::STATUS_FAILED)->doesntExist()) {
            return;
        }
        $batch->items()->where('status', PhotoBatchItem::STATUS_FAILED)->update(['status' => PhotoBatchItem::STATUS_PENDING, 'error' => null]);
        $batch->update(['status' => PhotoBatch::STATUS_QUEUED, 'archive_path' => null, 'finished_at' => null]);
        ProcessPhotoBatchJob::dispatch($batch->id);
    }
}
