<?php

namespace App\Livewire\Concerns;

use App\Models\QuotePartsRfq;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use Throwable;

/**
 * Ответ поставщику из карточки запроса QuoteParts (livewire.quote-parts._rfq-card):
 * вопросы поставщика и переписка видны в карточке, ответ уходит письмом от MyZip в его
 * переписку. Общий код панели заявки и «Снабжения»; какие запросы доступны — решает
 * компонент (quotePartsRfqForAnswer).
 */
trait AnswersQuotePartsSuppliers
{
    /** @var array<int|string, array<int|string, string>> rfq id => индекс поставщика в снимке => текст ответа */
    public array $qpAnswers = [];

    abstract protected function quotePartsRfqForAnswer(int $id): QuotePartsRfq;

    public function canAnswerQuoteParts(): bool
    {
        return QuotePartsPilot::enabledFor(auth()->user());
    }

    public function answerQuotePartsSupplier(int $rfqId, int $supplierIndex, QuotePartsRfqService $service): void
    {
        abort_unless($this->canAnswerQuoteParts(), 403);
        $rfq = $this->quotePartsRfqForAnswer($rfqId);

        try {
            $service->answer($rfq, $supplierIndex, (string) ($this->qpAnswers[$rfqId][$supplierIndex] ?? ''), auth()->user());
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }

        unset($this->qpAnswers[$rfqId][$supplierIndex]);
        $this->afterQuotePartsAnswer();
        $this->dispatch('toast', type: 'success', message: 'Ответ отправлен поставщику через QuoteParts.');
    }

    /** Сбросить кэш списка запросов компонента после ответа. */
    protected function afterQuotePartsAnswer(): void {}
}
