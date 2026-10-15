<?php

namespace App\Livewire\Concerns;

use App\Models\QuotePartsRfq;
use App\Services\QuoteParts\QuotePartsAnswerTranslator;
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

    /** @var array<int|string, array<int|string, string>> rfq id => группа вопросов => ответ по-русски */
    public array $qpGroupRu = [];

    /** @var array<int|string, array<int|string, string>> rfq id => группа вопросов => перевод для англоязычных поставщиков */
    public array $qpGroupEn = [];

    /**
     * Нужен ли перевод: среди спросивших есть поставщик с языком общения English.
     *
     * @param  array<string, mixed>  $group  группа из снимка запроса
     */
    public function quotePartsGroupNeedsEnglish(array $group): bool
    {
        $askers = collect($group['questions'] ?? [])->where('status', 'open')->pluck('supplier')->filter();

        return in_array('en', app(QuotePartsRfqService::class)->supplierLanguages($askers), true);
    }

    /** Перевести русский ответ для англоязычных поставщиков (перевод можно поправить). */
    public function translateQuotePartsGroup(int $rfqId, int $groupId, QuotePartsAnswerTranslator $translator): void
    {
        abort_unless($this->canAnswerQuoteParts(), 403);
        $en = $translator->toEnglish((string) ($this->qpGroupRu[$rfqId][$groupId] ?? ''));
        if ($en === null) {
            $this->dispatch('toast', type: 'error', message: 'Не удалось перевести — напишите английский вариант сами.');

            return;
        }
        $this->qpGroupEn[$rfqId][$groupId] = $en;
    }

    public function answerQuotePartsGroup(int $rfqId, int $groupId, QuotePartsRfqService $service): void
    {
        abort_unless($this->canAnswerQuoteParts(), 403);
        $rfq = $this->quotePartsRfqForAnswer($rfqId);

        try {
            $service->answerGroup($rfq, $groupId, (string) ($this->qpGroupRu[$rfqId][$groupId] ?? ''), $this->qpGroupEn[$rfqId][$groupId] ?? null, auth()->user());
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }

        unset($this->qpGroupRu[$rfqId][$groupId], $this->qpGroupEn[$rfqId][$groupId]);
        $this->afterQuotePartsAnswer();
        $this->dispatch('toast', type: 'success', message: 'Ответ отправлен всем, кто спросил.');
    }

    /** Сбросить кэш списка запросов компонента после ответа. */
    protected function afterQuotePartsAnswer(): void {}
}
