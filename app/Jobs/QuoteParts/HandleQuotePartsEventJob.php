<?php

namespace App\Jobs\QuoteParts;

use App\Models\QuotePartsRfq;
use App\Notifications\QuotePartsSupplierNotification;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Событие QuoteParts по нашему запросу: обновить снимок (ответы видны в заявке сразу),
 * а если поставщик ответил или спросил — сказать автору запроса в «колокольчике».
 */
class HandleQuotePartsEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  list<string>  $questions
     */
    public function __construct(
        public int $remoteId,
        public string $event,
        public ?string $supplierKey = null,
        public array $questions = [],
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(QuotePartsRfqService $service): void
    {
        $rfq = QuotePartsRfq::query()->where('remote_id', $this->remoteId)->first();
        if ($rfq === null) {
            return; // не наш запрос (или создан не из mzcorp)
        }

        try {
            $service->refresh($rfq);
        } catch (Throwable $e) {
            Log::warning('QuoteParts event: refresh failed', ['rfq' => $this->remoteId, 'event' => $this->event, 'error' => $e->getMessage()]);

            throw $e;
        }

        if ($this->event === 'supplier.replied') {
            try {
                app(\App\Services\QuoteParts\QuotePartsPriceService::class)->syncOffers();
            } catch (Throwable $e) {
                Log::warning('QuoteParts offers sync failed', ['error' => $e->getMessage()]);
            }
        }

        if (! in_array($this->event, ['supplier.replied', 'supplier.question'], true) || $rfq->createdBy === null) {
            return;
        }

        $supplierName = $this->supplierKey !== null
            ? ($service->supplierNames([$rfq])[$this->supplierKey] ?? null)
            : null;

        $rfq->createdBy->notify(new QuotePartsSupplierNotification($rfq, $this->event, $supplierName, $this->questions));
    }
}
