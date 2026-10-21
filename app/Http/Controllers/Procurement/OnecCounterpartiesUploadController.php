<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Services\Procurement\SupplierOnecCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Загрузка справочника контрагентов 1С УНФ («Список контрагентов», xlsx) обычной формой —
 * коды поставщиков для выгрузки цен в 1С. Файл на ~10 тыс. строк разбирается сразу.
 */
class OnecCounterpartiesUploadController extends Controller
{
    public function __invoke(Request $request, SupplierOnecCodeService $codes): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:26624']], [], ['file' => 'файл']);

        try {
            $stats = $codes->import($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            report($e);

            return redirect()->back()->withErrors(['onec_file' => 'Не удалось прочитать список контрагентов: '.mb_substr($e->getMessage(), 0, 300)]);
        }

        return redirect()->back()->with('status', 'Справочник контрагентов 1С загружен: '.$stats['rows'].' контрагентов (новых '.$stats['created'].').');
    }
}
