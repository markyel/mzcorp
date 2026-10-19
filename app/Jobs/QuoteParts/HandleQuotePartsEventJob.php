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
        $rfq = QuotePartsRfq::query()->where('remote_id', $this->remoteId)->first() ?? $this->adoptSupplierQuestion($service);
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

        if (! in_array($this->event, ['supplier.replied', 'supplier.question'], true)) {
            return;
        }

        $supplierName = $this->supplierKey !== null
            ? ($service->supplierNames([$rfq])[$this->supplierKey] ?? null)
            : null;

        // Запрос, открытый вопросом поставщика по позиции из номенклатуры, — общий для снабжения:
        // «колокольчик» всем снабженцам, а вопросы ещё и письмом.
        if ($rfq->isSupplierQuestion()) {
            $staff = \App\Models\User::role(\App\Enums\Role::Procurement->value)->whereNull('archived_at')->get();
            foreach ($staff as $user) {
                $user->notify(new QuotePartsSupplierNotification($rfq, $this->event, $supplierName, $this->questions));
                if ($this->event === 'supplier.question' && $user->email) {
                    app(\App\Services\Mail\SystemNotificationMailer::class)->sendMailable(
                        $user->email,
                        new \App\Mail\QuotePartsItemQuestionMail($rfq, (string) ($supplierName ?: $this->supplierKey), $this->questions),
                    );
                }
            }

            return;
        }

        $rfq->createdBy?->notify(new QuotePartsSupplierNotification($rfq, $this->event, $supplierName, $this->questions));
    }

    /**
     * Запроса нет у нас — его открыл сам поставщик вопросом по позиции из номенклатуры
     * (QuoteParts: origin supplier_question). Заводим его без заявки и без автора — его ведёт
     * снабжение («Снабжение» → «Запросы через QuoteParts»). Чужие запросы по-прежнему пропускаем.
     */
    private function adoptSupplierQuestion(QuotePartsRfqService $service): ?QuotePartsRfq
    {
        try {
            $snapshot = app(\App\Services\QuoteParts\QuotePartsClient::class)->rfq($this->remoteId);
        } catch (Throwable $e) {
            Log::warning('QuoteParts event: unknown request not fetched', ['rfq' => $this->remoteId, 'error' => $e->getMessage()]);

            return null;
        }
        if (($snapshot['origin'] ?? null) !== QuotePartsRfq::ORIGIN_SUPPLIER_QUESTION) {
            return null;
        }

        return QuotePartsRfq::query()->firstOrCreate(['remote_id' => $this->remoteId], [
            'tag' => $snapshot['tag'] ?? null,
            'request_id' => null,
            'created_by_user_id' => null,
            'reply_by' => $snapshot['reply_by'] ?? null,
            'status' => $snapshot['status'] ?? 'open',
            'snapshot' => $snapshot,
            'synced_at' => now(),
        ]);
    }
}
