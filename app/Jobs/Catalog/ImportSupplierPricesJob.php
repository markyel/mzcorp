<?php

namespace App\Jobs\Catalog;

use App\Models\SupplierPriceImport;
use App\Services\Catalog\CatalogSupplierPriceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Разбор (пробный прогон) или запись выгрузки 1С «Номенклатура (история цен)».
 *
 * Разбор файла тяжёлый (десятки секунд, гигабайты памяти) — дольше retry_after
 * очереди (90 с). Поэтому lock на загрузку и проверка статуса: повторная выдача
 * того же задания другим воркером ничего не делает.
 */
class ImportSupplierPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId, public bool $apply = false) {}

    public function handle(CatalogSupplierPriceImportService $service): void
    {
        $lock = Cache::lock('supplier-price-import:'.$this->importId, 900);
        if (! $lock->get()) {
            return;
        }

        try {
            $import = SupplierPriceImport::find($this->importId);
            $expected = $this->apply ? SupplierPriceImport::STATUS_PARSED : SupplierPriceImport::STATUS_UPLOADED;
            if ($import === null || $import->status !== $expected) {
                return;
            }

            $import->update(['status' => $this->apply ? SupplierPriceImport::STATUS_APPLYING : SupplierPriceImport::STATUS_PARSING]);
            ini_set('memory_limit', '3G');
            $started = microtime(true);

            $res = $service->parse(Storage::disk('local')->path($import->file_path));

            if (! $this->apply) {
                $import->update([
                    'status' => SupplierPriceImport::STATUS_PARSED,
                    'stats' => $res['stats'] + $service->diff($res['rows']) + [
                        'seconds' => (int) round(microtime(true) - $started),
                        'peak_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
                    ],
                    'unmatched_suppliers' => array_slice($res['unmatched_suppliers'], 0, 30, true),
                ]);

                return;
            }

            $written = $service->apply($res['rows'], $import->original_name);
            // Новые поставщики и закупки — регионы и группы товаров Китай / Россия / Европа заново.
            try {
                $groups = app(\App\Services\Catalog\SupplyGroupService::class);
                $groups->refreshRegions();
                $groups->rebuild();
                // Подписки пула QuoteParts: новые подходящие позиции — в пул, изменившиеся группы — в QuoteParts.
                \App\Jobs\QuoteParts\SyncQuotePartsPoolJob::dispatch();
            } catch (Throwable $e) {
                Log::warning('Supply groups rebuild failed', ['import_id' => $this->importId, 'error' => $e->getMessage()]);
            }
            $import->update([
                'status' => SupplierPriceImport::STATUS_APPLIED,
                'written' => $written,
                'applied_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Supplier price import failed', ['import_id' => $this->importId, 'apply' => $this->apply, 'error' => $e->getMessage()]);
            SupplierPriceImport::whereKey($this->importId)->update([
                'status' => SupplierPriceImport::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
        } finally {
            $lock->release();
        }
    }
}
