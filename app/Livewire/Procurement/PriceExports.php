<?php

namespace App\Livewire\Procurement;

use App\Models\PriceExport;
use App\Services\Procurement\PriceExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\Component;
use Throwable;

/**
 * Вкладка «Выгрузки цен» в «Снабжении» (и блок на вкладке «Актуализация цен» QuoteParts):
 * Excel с новыми ценами поставщиков под загрузку в 1С. Два режима: «новое» — с конца прошлой
 * выгрузки до сейчас (история выгрузок общая, цены не повторяются и не теряются), и за период.
 */
class PriceExports extends Component
{
    /** Компактный блок (вкладка QuoteParts): кнопки и последние выгрузки. */
    public bool $compact = false;

    public string $from = '';

    public string $to = '';

    public ?string $error = null;

    public function mount(): void
    {
        $last = PriceExport::query()->latest('to_at')->value('to_at');
        $this->from = ($last ? Carbon::parse($last) : now()->subDays(7)->startOfDay())->format('Y-m-d\TH:i');
        $this->to = now()->format('Y-m-d\TH:i');
    }

    /** Конец прошлой выгрузки — начало «нового» (первая выгрузка — за неделю). */
    public function lastExportEnd(): Carbon
    {
        $last = PriceExport::query()->latest('to_at')->value('to_at');

        return $last ? Carbon::parse($last) : now()->subDays(7)->startOfDay();
    }

    /** «Выгрузить новое»: всё, что пришло после прошлой выгрузки, до сейчас. */
    public function exportNew(PriceExportService $service): ?StreamedResponse
    {
        $this->from = $this->lastExportEnd()->format('Y-m-d\TH:i');
        $this->to = now()->format('Y-m-d\TH:i');

        return $this->export($service);
    }

    /** Сформировать и сразу скачать файл (он остаётся и в списке выгрузок). */
    public function export(PriceExportService $service): ?StreamedResponse
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

            return null;
        }

        $this->from = $export->to_at->format('Y-m-d\TH:i');
        $this->to = now()->format('Y-m-d\TH:i');
        $this->dispatch('toast', type: 'success', message: 'Выгрузка готова: '.$export->rows_count.' артикулов, '.$export->suppliers_count.' поставщиков.');

        return Storage::disk(config('procurement.price_export.disk', 'local'))->download($export->file_path, basename($export->file_path));
    }

    public function render()
    {
        return view('livewire.procurement.price-exports', [
            'exports' => PriceExport::query()->with('createdBy:id,name')->latest('id')->limit($this->compact ? 5 : 50)->get(),
            'lastEnd' => $this->lastExportEnd(),
        ]);
    }
}
