<?php

namespace App\Livewire\Photos;

use App\Models\PhotoBatch;
use App\Services\Photos\ClippingMagicClient;
use App\Services\Photos\PhotoBatchService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Раздел «Фото»: загрузить пачку фото (файлы или zip; имя файла — M-артикул), очистить фон в Clipping Magic,
 * скачать архив с теми же именами. Фото позиций попадают в каталог как «фото без водяного знака».
 * Файлы грузятся по одному (Alpine зовёт $wire.upload на каждый) — пачка любого размера.
 */
class Index extends Component
{
    use WithFileUploads;

    /** Загружаемый файл (по одному): фото или zip, до 29 МБ (лимит загрузки PHP — 30 МБ). */
    #[Validate('file|max:29696')]
    public $upload;

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    #[Computed]
    public function draft(): PhotoBatch
    {
        return app(PhotoBatchService::class)->draftFor(auth()->user());
    }

    /** Пачки пользователя в работе и готовые (последние 20). */
    #[Computed]
    public function batches(): Collection
    {
        return PhotoBatch::query()->where('user_id', auth()->id())->where('status', '!=', PhotoBatch::STATUS_DRAFT)
            ->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function inWork(): bool
    {
        return $this->batches->contains(fn (PhotoBatch $b) => $b->inWork());
    }

    #[Computed]
    public function account(): ?array
    {
        return app(ClippingMagicClient::class)->account();
    }

    public function updatedUpload(): void
    {
        $this->validate();
        try {
            app(PhotoBatchService::class)->addUpload($this->draft, $this->upload);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 200));
        }
        $this->upload = null;
        unset($this->draft);
    }

    public function removeItem(int $itemId, PhotoBatchService $service): void
    {
        $service->removeItem($this->draft, $itemId);
        unset($this->draft);
    }

    public function clearDraft(PhotoBatchService $service): void
    {
        foreach ($this->draft->items as $item) {
            $service->removeItem($this->draft, $item->id);
        }
        unset($this->draft);
    }

    public function start(PhotoBatchService $service): void
    {
        try {
            $service->start($this->draft);
            $this->notice = 'Пачка отправлена на очистку фона — архив появится ниже, когда всё будет готово.';
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 200));
        }
        unset($this->draft, $this->batches, $this->inWork);
    }

    public function retry(int $batchId, PhotoBatchService $service): void
    {
        $batch = PhotoBatch::query()->where('user_id', auth()->id())->findOrFail($batchId);
        $service->retryFailed($batch);
        unset($this->batches, $this->inWork);
    }

    public function render()
    {
        return view('livewire.photos.index');
    }
}
