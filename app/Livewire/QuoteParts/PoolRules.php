<?php

namespace App\Livewire\QuoteParts;

use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use App\Models\QuotePartsPoolItem;
use App\Models\QuotePartsPoolRule;
use App\Models\SupplyGroup;
use App\Services\QuoteParts\QuotePartsPoolRuleService;
use App\Services\QuoteParts\QuotePartsPoolService;
use Livewire\Component;
use Throwable;

/**
 * Подписки пула QuoteParts (вкладка «В QuoteParts»): группа товаров + фильтры. Сколько
 * позиций попадёт — видно до сохранения; подходящие держатся в пуле сами, передача идёт
 * в фоне порциями; переставшие подходить — список «к удалению».
 */
class PoolRules extends Component
{
    public bool $formOpen = false;

    public string $name = '';

    public string $groupCode = '';

    public string $purchasedYears = '3';

    public string $requestedDays = '';

    public bool $inStock = false;

    public bool $priceNotActual = false;

    public string $brands = '';

    public bool $staleOpen = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);
        $this->groupCode = (string) (SupplyGroup::codes()[0] ?? '');
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return array_filter([
            'purchased_years' => ctype_digit($this->purchasedYears) && (int) $this->purchasedYears > 0 ? (int) $this->purchasedYears : null,
            'requested_days' => ctype_digit($this->requestedDays) && (int) $this->requestedDays > 0 ? (int) $this->requestedDays : null,
            'in_stock' => $this->inStock ?: null,
            'price_not_actual' => $this->priceNotActual ?: null,
            'brands' => array_values(array_filter(array_map('trim', explode(',', $this->brands)))) ?: null,
        ], fn ($v) => $v !== null);
    }

    public function save(QuotePartsPoolRuleService $rules): void
    {
        $this->validate([
            'groupCode' => ['nullable', 'in:'.implode(',', SupplyGroup::codes())],
            'purchasedYears' => ['nullable', 'integer', 'min:1', 'max:30'],
            'requestedDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'brands' => ['nullable', 'string', 'max:500'],
            'name' => ['nullable', 'string', 'max:255'],
        ], [], ['purchasedYears' => 'годы закупок', 'requestedDays' => 'дни запросов']);

        $rule = QuotePartsPoolRule::create([
            'name' => trim($this->name),
            'group_code' => $this->groupCode ?: null,
            'filters' => $this->filters(),
            'is_active' => true,
            'created_by_user_id' => auth()->id(),
        ]);
        if ($rule->name === '') {
            $rule->forceFill(['name' => $rule->describe()])->save();
        }
        $added = $rules->apply($rule, auth()->user());
        SyncQuotePartsPoolJob::dispatch();
        $this->reset('formOpen', 'name', 'requestedDays', 'inStock', 'priceNotActual', 'brands');
        $this->dispatch('toast', type: 'success', message: 'Подписка сохранена: в пул добавлено '.$added.' позиций — передаются в QuoteParts в фоне порциями.');
    }

    public function toggle(int $id, QuotePartsPoolRuleService $rules): void
    {
        $rule = QuotePartsPoolRule::query()->findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
        if ($rule->is_active) {
            $rules->apply($rule, auth()->user());
            SyncQuotePartsPoolJob::dispatch();
        }
    }

    public function applyNow(int $id, QuotePartsPoolRuleService $rules): void
    {
        $added = $rules->apply(QuotePartsPoolRule::query()->findOrFail($id), auth()->user());
        SyncQuotePartsPoolJob::dispatch();
        $this->dispatch('toast', type: 'success', message: 'Добавлено в пул: '.$added.'.');
    }

    /** Подписка удаляется; её позиции остаются в пуле, пока их не уберут (они появятся «к удалению»). */
    public function delete(int $id): void
    {
        QuotePartsPoolRule::query()->whereKey($id)->delete();
    }

    /** Убрать из QuoteParts позиции, переставшие подходить к подпискам. */
    public function removeStale(QuotePartsPoolRuleService $rules, QuotePartsPoolService $pool): void
    {
        $ids = $rules->stale()->pluck('id')->all();
        $removed = 0;
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $removed += $pool->remove($chunk);
            }
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200));

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Убрано из QuoteParts: '.$removed.'. История там сохраняется.');
    }

    public function render(QuotePartsPoolRuleService $rules)
    {
        $stale = $rules->stale();

        return view('livewire.quoteparts.pool-rules', [
            'rulesList' => QuotePartsPoolRule::query()->orderBy('id')->get(),
            'groups' => SupplyGroup::ordered(),
            'preview' => $this->formOpen ? $rules->preview($this->groupCode ?: null, $this->filters()) : null,
            'staleCount' => (clone $stale)->count(),
            'staleItems' => $this->staleOpen ? (clone $stale)->with('catalogItem:id,sku,name')->orderBy('sku')->limit(200)->get() : collect(),
            'pending' => QuotePartsPoolItem::query()->inPool()->whereIn('status', [QuotePartsPoolItem::STATUS_PENDING, QuotePartsPoolItem::STATUS_ERROR])->count(),
            'inPool' => QuotePartsPoolItem::query()->inPool()->count(),
        ]);
    }
}
