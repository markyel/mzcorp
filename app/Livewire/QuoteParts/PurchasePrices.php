<?php

namespace App\Livewire\QuoteParts;

use App\Jobs\Catalog\ImportSupplierPricesJob;
use App\Models\SupplierPriceImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Загрузка выгрузки 1С «Номенклатура (история цен)» — первая и последняя закупка
 * по позициям. Файл → пробный разбор в фоне со сводкой → подтверждение записи.
 * Изменившиеся закупки позиций пула уходят в QuoteParts при часовой синхронизации.
 */
class PurchasePrices extends Component
{
    use WithFileUploads;

    public $file;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);
    }

    public function upload(): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:26624']], [], ['file' => 'файл']);

        if (SupplierPriceImport::query()->whereIn('status', [SupplierPriceImport::STATUS_UPLOADED, SupplierPriceImport::STATUS_PARSING, SupplierPriceImport::STATUS_APPLYING])->exists()) {
            $this->addError('file', 'Предыдущая загрузка ещё обрабатывается — дождитесь её.');

            return;
        }

        $name = $this->file->getClientOriginalName();
        $path = $this->file->storeAs('imports/1c', now()->format('Ymd-His').'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).'.xlsx', 'local');

        $import = SupplierPriceImport::create([
            'user_id' => auth()->id(),
            'file_path' => $path,
            'original_name' => $name,
            'status' => SupplierPriceImport::STATUS_UPLOADED,
        ]);

        ImportSupplierPricesJob::dispatch($import->id);
        $this->reset('file');
        unset($this->imports);
    }

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
