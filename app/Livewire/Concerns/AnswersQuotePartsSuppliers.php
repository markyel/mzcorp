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

    /**
     * Отвечать поставщикам по запросу QuoteParts: участники пилота, снабжение и руководство —
     * как обращения к поставщикам (QuotePartsPilot::canConverse). Раньше — только пилот, и
     * админ не видел, как ответить UniSystem на вопрос по M-2026-19719. Общий рубильник пилота
     * не нужен: он про новые запросы через QuoteParts, а на уже отправленные отвечать надо всегда.
     */
    public function canAnswerQuoteParts(): bool
    {
        return QuotePartsPilot::canAnswer(auth()->user());
    }

    /** Язык, на котором работает поставщик (ru / en): ответ ему пишут только на нём. */
    public function quotePartsSupplierLanguage(?string $supplierKey): string
    {
        return $supplierKey ? (app(QuotePartsRfqService::class)->supplierLanguages([$supplierKey])[$supplierKey] ?? 'ru') : 'ru';
    }

    /** @var array<int|string, array<int|string, list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile>>> файлы ответа поставщику */
    public array $qpFiles = [];

    /** @var array<int|string, array<int|string, string>> копия ответа (адреса) */
    public array $qpCc = [];

    /** @var array<int|string, array<int|string, bool>> ответ без подписи */
    public array $qpNoSign = [];

    public function removeQuotePartsFile(int $rfqId, int $supplierIndex, int $i): void
    {
        unset($this->qpFiles[$rfqId][$supplierIndex][$i]);
        $this->qpFiles[$rfqId][$supplierIndex] = array_values($this->qpFiles[$rfqId][$supplierIndex] ?? []);
    }

    /** Перевести ответ на английский для англоязычного поставщика: перевод встаёт в поле, его можно поправить. */
    public function translateQuotePartsAnswer(int $rfqId, int $supplierIndex, QuotePartsAnswerTranslator $translator): void
    {
        abort_unless($this->canAnswerQuoteParts(), 403);
        $en = $translator->toEnglish((string) ($this->qpAnswers[$rfqId][$supplierIndex] ?? ''));
        if ($en === null) {
            $this->dispatch('toast', type: 'error', message: 'Не удалось перевести — напишите по-английски сами.');

            return;
        }
        $this->qpAnswers[$rfqId][$supplierIndex] = $en;
    }

    public function answerQuotePartsSupplier(int $rfqId, int $supplierIndex, QuotePartsRfqService $service): void
    {
        abort_unless($this->canAnswerQuoteParts(), 403);
        $this->validate(["qpFiles.$rfqId.$supplierIndex.*" => ['file', 'max:25600']], [], ["qpFiles.$rfqId.$supplierIndex.*" => 'вложение']);
        $rfq = $this->quotePartsRfqForAnswer($rfqId);

        try {
            $service->answer(
                $rfq,
                $supplierIndex,
                (string) ($this->qpAnswers[$rfqId][$supplierIndex] ?? ''),
                auth()->user(),
                array_values($this->qpFiles[$rfqId][$supplierIndex] ?? []),
                (string) ($this->qpCc[$rfqId][$supplierIndex] ?? ''),
                ! ($this->qpNoSign[$rfqId][$supplierIndex] ?? false),
            );
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }

        unset($this->qpAnswers[$rfqId][$supplierIndex], $this->qpFiles[$rfqId][$supplierIndex], $this->qpCc[$rfqId][$supplierIndex]);
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

    /** @var array<int|string, array<int|string, list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile>>> файлы ответа на группу вопросов */
    public array $qpGroupFiles = [];

    public function answerQuotePartsGroup(int $rfqId, int $groupId, QuotePartsRfqService $service): void
    {
        abort_unless($this->canAnswerQuoteParts(), 403);
        $this->validate(["qpGroupFiles.$rfqId.$groupId.*" => ['file', 'max:25600']], [], ["qpGroupFiles.$rfqId.$groupId.*" => 'вложение']);
        $rfq = $this->quotePartsRfqForAnswer($rfqId);

        try {
            $service->answerGroup($rfq, $groupId, (string) ($this->qpGroupRu[$rfqId][$groupId] ?? ''), $this->qpGroupEn[$rfqId][$groupId] ?? null, auth()->user(),
                array_values($this->qpGroupFiles[$rfqId][$groupId] ?? []));
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }

        unset($this->qpGroupRu[$rfqId][$groupId], $this->qpGroupEn[$rfqId][$groupId], $this->qpGroupFiles[$rfqId][$groupId]);
        $this->afterQuotePartsAnswer();
        $this->dispatch('toast', type: 'success', message: 'Ответ отправлен всем, кто спросил.');
    }

    /** Сбросить кэш списка запросов компонента после ответа. */
    protected function afterQuotePartsAnswer(): void {}
}
