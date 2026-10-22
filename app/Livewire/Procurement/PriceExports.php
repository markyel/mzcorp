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
        // Выгрузки по списку позиций «Актуализации цен» период не закрывают.
        $last = PriceExport::query()->where('kind', PriceExport::KIND_PERIOD)->latest('to_at')->value('to_at');
        $this->from = ($last ? Carbon::parse($last) : now()->subDays(7)->startOfDay())->format('Y-m-d\TH:i');
        $this->to = now()->format('Y-m-d\TH:i');
    }

    /** Ручной код 1С поставщика: supplier_key => код. */
    public array $manualCodes = [];

    /**
     * Поставщики с ценами за 30 дней, у которых нет кода 1С: название, подсказки из справочника.
     *
     * @return list<array{key:string, name:string, suggestions:\Illuminate\Support\Collection}>
     */
    #[\Livewire\Attributes\Computed]
    public function withoutCode(): array
    {
        if (\App\Models\OnecCounterparty::query()->doesntExist()) {
            return [];
        }
        $service = app(PriceExportService::class);
        $codes = app(\App\Services\Procurement\SupplierOnecCodeService::class);
        [$prices] = $service->collect(now()->subDays(30), now());
        $keys = $prices->pluck('key')->filter()->unique()->values()->all();
        $names = $service->names1c($keys);
        $found = $codes->codesFor($keys, $names);

        $out = [];
        foreach ($keys as $key) {
            if (isset($found[$key])) {
                continue;
            }
            $name = $names[$key] ?? (string) $prices->firstWhere('key', $key)['fallback'];
            $out[] = ['key' => $key, 'name' => $name, 'suggestions' => $codes->suggestions($codes->registryNames($key)[0] ?? $name)];
        }

        return $out;
    }

    /** Привязать поставщика к контрагенту 1С (кнопкой подсказки или введённым кодом). */
    public function setCode(string $supplierKey, ?string $code = null): void
    {
        $code ??= (string) ($this->manualCodes[$supplierKey] ?? '');
        try {
            app(\App\Services\Procurement\SupplierOnecCodeService::class)->setCode($supplierKey, $code, auth()->user());
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        unset($this->withoutCode, $this->manualCodes[$supplierKey]);
        $this->dispatch('toast', type: 'success', message: 'Код 1С '.$code.' привязан.');
    }

    /** Конец прошлой выгрузки — начало «нового» (первая выгрузка — за неделю). */
    public function lastExportEnd(): Carbon
    {
        // Выгрузки по списку позиций «Актуализации цен» период не закрывают.
        $last = PriceExport::query()->where('kind', PriceExport::KIND_PERIOD)->latest('to_at')->value('to_at');

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
