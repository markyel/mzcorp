<?php

namespace App\Livewire\Procurement;

use App\Models\PriceExport;
use App\Services\Procurement\PriceExportService;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Throwable;

/**
 * Вкладка «Выгрузки цен» в «Снабжении»: Excel с новыми ценами поставщиков под загрузку в 1С.
 * По умолчанию — с конца прошлой выгрузки до сейчас; период можно задать руками.
 */
class PriceExports extends Component
{
    public string $from = '';

    public string $to = '';

    public ?string $error = null;

    public function mount(): void
    {
        $last = PriceExport::query()->latest('to_at')->value('to_at');
        $this->from = ($last ? Carbon::parse($last) : now()->subDays(7)->startOfDay())->format('Y-m-d\TH:i');
        $this->to = now()->format('Y-m-d\TH:i');
    }

    public function export(PriceExportService $service): void
    {
        $this->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ], [], ['from' => 'начало периода', 'to' => 'конец периода']);
        $this->error = null;

        try {
            $export = $service->export(Carbon::parse($this->from), Carbon::parse($this->to), auth()->user());
        } catch (Throwable $e) {
            report($e);
            $this->error = 'Не удалось сформировать выгрузку: '.mb_substr($e->getMessage(), 0, 300);

            return;
        }

        $this->from = $export->to_at->format('Y-m-d\TH:i');
        $this->to = now()->format('Y-m-d\TH:i');
        $this->dispatch('toast', type: 'success', message: 'Выгрузка готова: '.$export->rows_count.' артикулов, '.$export->suppliers_count.' поставщиков.');
    }

    public function render()
    {
        return view('livewire.procurement.price-exports', [
            'exports' => PriceExport::query()->with('createdBy:id,name')->latest('id')->limit(50)->get(),
        ]);
    }
}
