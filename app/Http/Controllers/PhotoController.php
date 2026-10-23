<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\PhotoBatch;
use App\Models\PhotoBatchItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Файлы раздела «Фото»: архив пачки и результат отдельного фото — автору пачки и руководству;
 * фото позиции без водяного знака (каталог) — всем сотрудникам.
 */
class PhotoController extends Controller
{
    public function archive(PhotoBatch $batch): StreamedResponse
    {
        $this->authorizeBatch($batch);
        abort_unless($batch->archive_path && Storage::disk('local')->exists($batch->archive_path), 404);

        return Storage::disk('local')->download($batch->archive_path, basename($batch->archive_path));
    }

    public function item(PhotoBatchItem $item): BinaryFileResponse
    {
        $this->authorizeBatch($item->batch);
        abort_unless($item->result_path && Storage::disk('local')->exists($item->result_path), 404);

        return response()->file(Storage::disk('local')->path($item->result_path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function catalogClean(int $id): BinaryFileResponse
    {
        $path = CatalogItem::query()->whereKey($id)->value('clean_photo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    private function authorizeBatch(PhotoBatch $batch): void
    {
        $user = auth()->user();
        abort_unless($user && ((int) $batch->user_id === (int) $user->id || $user->hasAnyRole(['director', 'admin', 'head_of_sales'])), 403);
    }
}
