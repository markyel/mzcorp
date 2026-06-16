<?php

namespace App\Livewire\Analytics;

use App\Enums\Role as RoleEnum;
use App\Models\CatalogPriceChange;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Аналитика изменения цен каталога: ретроспектива «было → стало» по
 * позициям. Видно, что дорожало, а что дешевело за период. Источник —
 * `catalog_price_changes` (пишется CatalogImportService при импорте MDB).
 *
 * Доступ: head_of_sales / director / secretary / admin (как и вся аналитика).
 */
class PriceChanges extends Component
{
    use WithPagination;

    /** Период по changed_at: 30 | 90 | 365 | all. */
    #[Url(as: 'period', except: '90')]
    public string $period = '90';

    /** Направление: all | up (подорожания) | down (удешевления). */
    #[Url(as: 'dir', except: 'all')]
    public string $direction = 'all';

    /** Поиск по SKU/названию. Заполняется из карточки каталога (?q=SKU). */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                RoleEnum::HeadOfSales->value,
                RoleEnum::Director->value,
                RoleEnum::Secretary->value,
                RoleEnum::Admin->value,
            ]),
            403,
            'Раздел доступен РОПу, директорату, секретарю и админам.',
        );
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['30', '90', '365', 'all'], true) ? $p : '90';
        $this->resetPage();
    }

    public function setDirection(string $d): void
    {
        $this->direction = in_array($d, ['all', 'up', 'down'], true) ? $d : 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $q = CatalogPriceChange::query()->with('catalogItem:id,sku,name');

        if ($this->period !== 'all') {
            $q->where('changed_at', '>=', now()->subDays((int) $this->period));
        }

        if ($this->direction === 'up') {
            $q->whereNotNull('old_price')->whereNotNull('new_price')
                ->whereColumn('new_price', '>', 'old_price');
        } elseif ($this->direction === 'down') {
            $q->whereNotNull('old_price')->whereNotNull('new_price')
                ->whereColumn('new_price', '<', 'old_price');
        }

        $needle = trim($this->search);
        if ($needle !== '') {
            $like = '%' . mb_strtolower($needle) . '%';
            $q->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(sku) LIKE ?', [$like])
                    ->orWhereHas('catalogItem', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        $changes = $q->orderByDesc('changed_at')->orderByDesc('id')->paginate(50);

        return view('livewire.analytics.price-changes', [
            'changes' => $changes,
        ]);
    }
}
