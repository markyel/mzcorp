<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Jobs\Catalog\ImportSupplierPricesJob;
use App\Models\SupplierPriceImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Загрузка выгрузки 1С «Номенклатура (история цен)» обычной формой (multipart POST),
 * без временной загрузки Livewire: та в Safari не стартовала после выбора файла.
 * Дальше — как прежде: пробный разбор в фоне, подтверждение записи на вкладке.
 */
class PurchasePriceUploadController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:26624']],
            [],
            ['file' => 'файл'],
        );

        $back = redirect()->route('quoteparts.index', ['tab' => 'prices']);

        if (SupplierPriceImport::query()->whereIn('status', [
            SupplierPriceImport::STATUS_UPLOADED, SupplierPriceImport::STATUS_PARSING, SupplierPriceImport::STATUS_APPLYING,
        ])->exists()) {
            return $back->withErrors(['file' => 'Предыдущая загрузка ещё обрабатывается — дождитесь её.']);
        }

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $path = $file->storeAs('imports/1c', now()->format('Ymd-His').'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.xlsx', 'local');

        $import = SupplierPriceImport::create([
            'user_id' => $request->user()->id,
            'file_path' => $path,
            'original_name' => $name,
            'status' => SupplierPriceImport::STATUS_UPLOADED,
        ]);
        ImportSupplierPricesJob::dispatch($import->id);

        return $back->with('status', 'Файл загружен, идёт проверка — сводка появится в таблице.');
    }
}
