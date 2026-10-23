<?php

namespace App\Jobs\Photos;

use App\Models\PhotoBatch;
use App\Models\PhotoBatchItem;
use App\Services\Photos\PhotoBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Очистка фона пачки фото порциями: обрабатывает фото ~50 секунд (retry_after очереди — 90 с) и ставит себя заново на
 * остаток — чтобы не держать воркер почты надолго и не упереться в таймаут. Фото кончились — архив.
 * Ошибка фото пишется в само фото (пачка идёт дальше).
 */
class ProcessPhotoBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Сколько секунд одна порция обрабатывает фото. */
    private const SLICE_SECONDS = 50;

    public int $tries = 3;

    public int $timeout = 85;

    public function __construct(public int $batchId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(PhotoBatchService $service): void
    {
        $batch = PhotoBatch::query()->find($this->batchId);
        if ($batch === null || ! $batch->inWork()) {
            return;
        }
        $batch->update(['status' => PhotoBatch::STATUS_PROCESSING]);

        $started = time();
        while (time() - $started < self::SLICE_SECONDS) {
            $item = $batch->items()->where('status', PhotoBatchItem::STATUS_PENDING)->orderBy('id')->first();
            if ($item === null) {
                $service->finish($batch->fresh());

                return;
            }
            $service->processItem($batch, $item);
        }

        // Время порции вышло — остаток следующей порцией.
        self::dispatch($this->batchId);
    }
}
