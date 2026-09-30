<?php

namespace App\Jobs\Suppliers;

use App\Enums\RequestActivityType;
use App\Models\EmailMessage;
use App\Models\SupplierInquiry;
use App\Services\Request\AttentionService;
use App\Services\Request\RequestActivityService;
use App\Services\Supplier\SupplierOfferParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Разбор ответа поставщика на RFQ в предложения по позициям (Фаза 3.3).
 * Диспатчится из SupplierInquiryService::attachMessage при прикреплении
 * ВХОДЯЩЕГО письма к запросу поставщику (если у запроса есть позиции).
 * Idempotent: ShouldBeUnique по email_message_id (10 мин).
 */
class ParseSupplierReplyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Сбой модели (OpenAI 429 утром 28.09) раньше молча превращал ответ с
     * ценой в «ответ без цены» — повтора не было. Теперь откладываем и
     * повторяем: 1, 5, 15, 60 минут.
     */
    public int $tries = 5;

    /** Задержки повтора после сбоя модели, секунды. */
    private const RETRY_DELAYS = [60, 300, 900, 3600];

    public function __construct(
        public int $emailMessageId,
        public int $supplierInquiryId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'supplier-reply-parse:' . $this->emailMessageId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        SupplierOfferParser $parser,
        AttentionService $attention,
        RequestActivityService $activity,
        \App\Services\Supplier\PriceRefreshReconciler $priceRefresh,
    ): void {
        $message = EmailMessage::find($this->emailMessageId);
        $inquiry = SupplierInquiry::find($this->supplierInquiryId);
        if ($message === null || $inquiry === null) {
            return;
        }
        // Письмо успели перенести в другую ветку (исправление привязки) —
        // разбирать против позиций старой нельзя: цены сядут на чужие позиции.
        if ((int) $message->supplier_inquiry_id !== $this->supplierInquiryId) {
            return;
        }

        $counts = $parser->parse($inquiry, $message);
        if (! empty($counts['failed'])) {
            $attempt = $this->attempts();
            if ($attempt < $this->tries) {
                $this->release(self::RETRY_DELAYS[min($attempt, count(self::RETRY_DELAYS)) - 1]);
            } else {
                Log::error('ParseSupplierReplyJob: model unavailable, reply left unparsed', [
                    'email_message_id' => $this->emailMessageId, 'supplier_inquiry_id' => $this->supplierInquiryId,
                ]);
            }

            return;
        }
        if ($counts['quoted'] === 0 && $counts['refused'] === 0) {
            return; // нечего отмечать (молчание/не распознано)
        }

        // Поднимаем флаг «ответ поставщика» на связанной заявке.
        $request = $inquiry->relatedRequest;
        if ($request !== null) {
            try {
                $attention->onSupplierReplied($request);
                $activity->touch($request->fresh() ?? $request, RequestActivityType::SupplierReplied, $message->sent_at);
                // Пересчитать цикл обновления цен: ответ мог закрыть позицию
                // отказом (possibly_discontinued) и/или довести заявку до
                // «все поставщики отказали».
                $priceRefresh->reconcile($request->fresh() ?? $request, markDiscontinued: true);
            } catch (\Throwable $e) {
                Log::warning('ParseSupplierReplyJob: attention/activity failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
