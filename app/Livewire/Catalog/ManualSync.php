<?php

namespace App\Livewire\Catalog;

use App\Models\CatalogManualSync;
use App\Services\Catalog\CatalogManualSyncService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Раздел «Синхронизация каталога»: загрузка выгрузки 1С с актуализированными ценами (обычной
 * формой — ManualSyncUploadController), разбор «было → стало», «Применить» и журнал загрузок.
 * Логика — CatalogManualSyncService.
 */
class ManualSync extends Component
{
    /** Открытая загрузка. */
    #[Url(as: 'sync')]
    public ?int $syncId = null;

    /** Фильтр строк разбора: all | changed | same | unknown | invalid. */
    #[Url(as: 'rows', except: 'all')]
    public string $rowFilter = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'procurement', 'admin']), 403);
        $this->syncId ??= CatalogManualSync::query()->where('status', CatalogManualSync::STATUS_PARSED)->latest('id')->value('id');
    }

    #[Computed]
    public function sync(): ?CatalogManualSync
    {
        return $this->syncId ? CatalogManualSync::query()->with(['user:id,name', 'appliedBy:id,name'])->find($this->syncId) : null;
    }

    #[Computed]
    public function history()
    {
        return CatalogManualSync::query()->with(['user:id,name'])->latest('id')->limit(20)->get();
    }

    public function open(int $id): void
    {
        $this->syncId = $id;
        $this->rowFilter = 'all';
        unset($this->sync);
    }

    public function apply(CatalogManualSyncService $service): void
    {
        $sync = $this->sync ?? abort(404);
        try {
            $r = $service->apply($sync, auth()->user());
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }
        unset($this->sync, $this->history);
        $this->dispatch('toast', type: 'success', message: 'Цены применены: обновлено позиций '.$r['updated'].', стали актуальными '.$r['became_actual'].'.');
    }

    public function cancel(): void
    {
        $sync = $this->sync ?? abort(404);
        if ($sync->status === CatalogManualSync::STATUS_PARSED) {
            $sync->forceFill(['status' => CatalogManualSync::STATUS_CANCELLED])->save();
        }
        unset($this->sync, $this->history);
    }

    public function render()
    {
        return view('livewire.catalog.manual-sync');
    }
}
