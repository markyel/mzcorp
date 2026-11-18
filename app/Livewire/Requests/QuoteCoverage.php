<?php

namespace App\Livewire\Requests;

use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Quotes\PartialQuoteRemainderService;
use App\Services\Quotes\QuoteCoverageService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Карточка заявки → «Позиции»: позиции, не покрытые ни одним отправленным КП («Частичное КП», 18.11.2026). По каждой —
 * «закрыто заменой», «мы отказали», «клиенту не нужно» (позиция отключается); снять отметку — «вернуть». Логика —
 * QuoteCoverageService; после действия карточка обновляет статус (request-state-changed).
 */
class QuoteCoverage extends Component
{
    public int $requestId;

    #[On('items-changed')]
    #[On('item-edited')]
    #[On('quote-item-rematched')]
    public function refreshCoverage(): void
    {
        unset($this->uncovered, $this->resolved, $this->hasQuote, $this->isClosed, $this->remainder);
    }

    #[Computed]
    public function hasQuote(): bool
    {
        $request = Request::find($this->requestId);

        return $request !== null && app(QuoteCoverageService::class)->hasQuote($request);
    }

    /** @return Collection<int, RequestItem> */
    #[Computed]
    public function uncovered(): Collection
    {
        $request = Request::find($this->requestId);

        return $request !== null && $this->hasQuote ? app(QuoteCoverageService::class)->uncovered($request) : collect();
    }

    /** Закрытой заявке не досылают КП — список «Не покрыто КП» с кнопками только у открытой. */
    #[Computed]
    public function isClosed(): bool
    {
        return (bool) Request::find($this->requestId)?->status->isTerminal();
    }

    /** Заявка-остаток (позиции без КП после оплаты частичного КП), если заведена. */
    #[Computed]
    public function remainder(): ?Request
    {
        $request = Request::find($this->requestId);

        return $request !== null ? app(PartialQuoteRemainderService::class)->remainderOf($request, openOnly: false) : null;
    }

    /** @return Collection<int, RequestItem> позиции, закрытые отметкой менеджера */
    #[Computed]
    public function resolved(): Collection
    {
        return RequestItem::query()->where('request_id', $this->requestId)->where('is_active', true)->whereNotNull('quote_resolution')
            ->orderBy('position')->orderBy('id')->get();
    }

    public function resolve(int $itemId, string $action, QuoteCoverageService $coverage): void
    {
        $item = RequestItem::query()->where('request_id', $this->requestId)->findOrFail($itemId);
        abort_unless(Request::find($this->requestId)?->isAccessibleBy(auth()->user()), 403);
        // Перенесённую в заявку-остаток позицию не возвращают: работа по ней идёт там.
        abort_if($item->quote_resolution === QuoteCoverageService::RESOLUTION_CARRIED_OVER || $this->isClosed, 422);

        if ($action === 'not_needed') {
            // «Клиенту не нужно» — позиция отключается; наблюдатель позиций пересчитает статус.
            $item->forceFill(['is_active' => false])->save();
        } else {
            $coverage->resolve($item, $action === 'reset' ? null : $action, auth()->id());
        }

        $this->refreshCoverage();
        $this->dispatch('request-state-changed');
        $this->dispatch('items-changed');
    }

    public function render()
    {
        return view('livewire.requests.quote-coverage');
    }
}
