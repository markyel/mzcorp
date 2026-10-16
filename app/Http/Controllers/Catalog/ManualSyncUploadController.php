<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogManualSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Загрузка выгрузки 1С с актуализированными ценами обычной формой (multipart POST), без
 * временной загрузки Livewire: та в Safari не стартовала после выбора файла. Разбор сразу —
 * файл небольшой; применяет человек кнопкой на странице.
 */
class ManualSyncUploadController extends Controller
{
    public function __invoke(Request $request, CatalogManualSyncService $service): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480']],
            [],
            ['file' => 'файл'],
        );

        $file = $request->file('file');
        $name = (string) $file->getClientOriginalName();
        $path = $file->storeAs('imports/catalog-sync', now()->format('Ymd-His').'-'.(Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'file').'.'.($file->getClientOriginalExtension() ?: 'xlsx'), 'local');

        try {
            $sync = $service->upload($path, $name, $request->user());
        } catch (Throwable $e) {
            Log::warning('CatalogManualSync: parse failed', ['file' => $name, 'error' => $e->getMessage()]);

            return redirect()->route('catalog-sync.index')->withErrors(['file' => 'Не удалось разобрать файл: '.$e->getMessage()]);
        }

        return redirect()->route('catalog-sync.index', ['sync' => $sync->id]);
    }
}
