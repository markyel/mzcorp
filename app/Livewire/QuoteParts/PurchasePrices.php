<?php

namespace App\Livewire\QuoteParts;

use App\Jobs\Catalog\ImportSupplierPricesJob;
use App\Models\SupplierPriceImport;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Загрузка выгрузки 1С «Номенклатура (история цен)» — первая и последняя закупка
 * по позициям. Файл → пробный разбор в фоне со сводкой → подтверждение записи.
 * Изменившиеся закупки позиций пула уходят в QuoteParts при часовой синхронизации.
 */
class PurchasePrices extends Component
{
    public function mount(): void
    {
        // Раздел QuoteParts открыт всем (23.10.2026).
        abort_unless(auth()->check(), 403);
    }

    // Сам файл принимает PurchasePriceUploadController (обычная форма, без загрузки Livewire).

    public function apply(int $id): void
    {
        $import = SupplierPriceImport::find($id);
        if ($import === null || $import->status !== SupplierPriceImport::STATUS_PARSED) {
            return;
        }

        ImportSupplierPricesJob::dispatch($import->id, apply: true);
        unset($this->imports);
        $this->dispatch('toast', type: 'success', message: 'Запись началась — займёт около минуты.');
    }

    public function discard(int $id): void
    {
        SupplierPriceImport::whereKey($id)->where('status', SupplierPriceImport::STATUS_PARSED)
            ->update(['status' => SupplierPriceImport::STATUS_FAILED, 'error' => 'Отменено без записи.']);
        unset($this->imports);
    }

    #[Computed]
    public function imports()
    {
        return SupplierPriceImport::query()->with('user:id,name')->latest('id')->limit(8)->get();
    }

    /** @return array{rows:int, newest:?string, loaded:?string} */
    #[Computed]
    public function current(): array
    {
        $r = DB::table('catalog_supplier_prices')->where('source', '1c')
            ->selectRaw('count(*) as n, max(priced_at) as newest, max(updated_at) as loaded')->first();

        return ['rows' => (int) $r->n, 'newest' => $r->newest, 'loaded' => $r->loaded];
    }

    public function render()
    {
        return view('livewire.quoteparts.purchase-prices', [
            'busy' => $this->imports->contains(fn ($i) => $i->isBusy()),
        ]);
    }
}
