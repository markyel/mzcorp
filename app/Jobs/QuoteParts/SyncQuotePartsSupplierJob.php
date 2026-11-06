<?php

namespace App\Jobs\QuoteParts;

use App\Services\QuoteParts\SupplierMirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Поставщик изменился в QuoteParts (принял или отклонил условия, поменял команду или карточку
 * компании — уведомление supplier.updated): обновить его копию в mzcorp.
 */
class SyncQuotePartsSupplierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $key) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SupplierMirrorService $mirror): void
    {
        $mirror->refresh($this->key);
    }
}
