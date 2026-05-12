<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Catalog\ResolvePendingFromCatalogJob;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/catalog/import` — приём snapshot'а из MDB.
 *
 * Auth: middleware `catalog.import.token` (см. CatalogImportToken).
 * Тело — JSON; см. CatalogImportService::import для контракта.
 *
 * Ответ:
 *   200: {"import_id":..., "mode":..., "rows_total":..., "rows_created":...,
 *         "rows_updated":..., "rows_unchanged":..., "rows_soft_deleted":...,
 *         "duration_ms":..., "errors":[...]}
 *   422: невалидный JSON / структура.
 *   401: bad token (middleware).
 */
class CatalogImportController extends Controller
{
    public function __construct(private readonly CatalogImportService $service)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->all();
        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Тело должно быть JSON с массивом items[].',
            ], 422);
        }

        $maxRows = (int) config('services.catalog_import.max_rows', 50000);
        if (count($data['items']) > $maxRows) {
            return response()->json([
                'error' => 'too_many_rows',
                'message' => "items > {$maxRows}",
            ], 422);
        }

        // Гард от случайного «обнуления каталога»: mode=full с подозрительно
        // маленьким snapshot'ом (пустой / неполная выгрузка / битый MDB на
        // офисе) сносит всё в is_active=false soft-delete'ом. Отказываем
        // на стороне сервера до записи в БД. Порог — config (env), дефолт 1
        // (просто запрет пустого payload'а).
        $minRows = (int) config('services.catalog_import.min_full_rows', 1);
        $mode = (string) ($data['mode'] ?? 'full');
        $rowCount = count($data['items']);
        if ($mode === 'full' && $rowCount < $minRows) {
            Log::warning('CatalogImportController: snapshot rejected as too small', [
                'rows_received' => $rowCount,
                'min_required' => $minRows,
                'source' => $data['source'] ?? null,
                'client_ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'snapshot_too_small',
                'message' => "items={$rowCount} < min_full_rows={$minRows}. Для mode=full порог защищает от случайного обнуления каталога. Поднимай CATALOG_IMPORT_MIN_FULL_ROWS если нужен меньший snapshot.",
                'rows_received' => $rowCount,
                'min_required' => $minRows,
            ], 422);
        }

        try {
            $import = $this->service->import($data, $request->ip());
        } catch (\Throwable $e) {
            Log::error('CatalogImportController: import failed', [
                'error' => $e->getMessage(),
                'trace_excerpt' => mb_substr($e->getTraceAsString(), 0, 1000),
            ]);

            return response()->json([
                'error' => 'import_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Если в snapshot'е что-то изменилось — запускаем bulk-резолв
        // позиций с internal_catalog_pending. ShouldBeUnique с TTL 1 минута
        // защищает от двойного запуска при retry скрипта.
        $touched = $import->rows_created + $import->rows_updated + $import->rows_soft_deleted;
        if ($touched > 0) {
            ResolvePendingFromCatalogJob::dispatch();
        }

        return response()->json([
            'import_id' => $import->id,
            'mode' => $import->mode,
            'rows_total' => $import->rows_total,
            'rows_created' => $import->rows_created,
            'rows_updated' => $import->rows_updated,
            'rows_unchanged' => $import->rows_unchanged,
            'rows_soft_deleted' => $import->rows_soft_deleted,
            'duration_ms' => $import->duration_ms,
            'errors' => $import->errors ?? [],
        ]);
    }
}
