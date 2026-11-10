<?php

namespace App\Livewire\Requests\Items;

use App\Models\CatalogItem;
use App\Models\Request as RequestModel;
use App\Services\Catalog\RequestItemEditor;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Inline-форма добавления позиции в существующую заявку (таб «Позиции»).
 *
 * Visibility / permissions — те же, что у ClarificationPanel:
 *   assigned manager + delegate + privileged head_of_sales / director.
 *   Секретарь — readonly.
 *
 * После save диспатчит `items-changed` — родительский Detail-компонент
 * на это событие перерисует список позиций и подытоги/счётчики.
 */
class AddItemForm extends Component
{
    public int $requestId;

    public bool $expanded = false;

    #[Validate('required|string|min:2|max:500')]
    public string $name = '';

    #[Validate('nullable|string|max:120')]
    public string $brand = '';

    #[Validate('nullable|string|max:120')]
    public string $article = '';

    #[Validate('required|numeric|min:0.001|max:99999')]
    public string $qty = '1';

    #[Validate('nullable|string|max:20')]
    public string $unit = 'шт.';

    #[Validate('nullable|string|max:1000')]
    public string $note = '';

    /** Быстрое заполнение по M-артикулу каталога: поле и позиция каталога, к которой привяжем. */
    public string $sku = '';

    public ?int $catalogItemId = null;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function toggle(): void
    {
        $this->expanded = ! $this->expanded;
        if (! $this->expanded) {
            $this->resetForm();
        }
    }

    public function cancel(): void
    {
        $this->expanded = false;
        $this->resetForm();
    }

    /**
     * «Заполнить» по M-артикулу: название, бренд и артикул производителя — из каталога; количество
     * и ед. изм. не трогаем. При сохранении позиция привязывается к позиции каталога (linkToCatalog).
     */
    public function fillFromSku(): void
    {
        $this->resetErrorBag('sku');
        $sku = self::normalizeSku($this->sku);
        if ($sku === null) {
            $this->addError('sku', 'Введите M-артикул, например M06374.');

            return;
        }
        $catalog = CatalogItem::query()->whereRaw('UPPER(sku) = ?', [$sku])->first();
        if ($catalog === null) {
            $this->catalogItemId = null;
            $this->addError('sku', "M-артикул {$sku} не найден в каталоге.");

            return;
        }
        if (! $catalog->is_active) {
            $this->catalogItemId = null;
            $this->addError('sku', "{$catalog->sku} исключён из каталога (неактивен).");

            return;
        }

        $this->sku = (string) $catalog->sku;
        $this->catalogItemId = (int) $catalog->id;
        $this->name = (string) $catalog->name;
        $this->brand = (string) ($catalog->brand ?? '');
        $this->article = (string) ($catalog->brand_article ?: (collect((array) $catalog->articles)->filter()->first() ?? ''));
        // Ед. изм. не трогаем: unit_name/units в каталоге — узел лифта («Кабина…»), не единица.
        $this->resetErrorBag(['name', 'brand', 'article']);
    }

    /** Другой M-артикул в поле — прежняя привязка больше не действует. */
    public function updatedSku(): void
    {
        $this->catalogItemId = null;
    }

    /** «м06374», «M 06374», «06374» → «M06374»; не похоже на M-артикул — null. */
    public static function normalizeSku(string $raw): ?string
    {
        $s = mb_strtoupper(str_replace(['М', 'м', ' '], ['M', 'M', ''], trim($raw)));
        if (preg_match('/^\d{4,6}$/', $s) === 1) {
            $s = 'M'.$s;
        }

        return preg_match('/^M\d{4,6}$/', $s) === 1 ? $s : null;
    }

    public function save(RequestItemEditor $editor): void
    {
        $request = RequestModel::findOrFail($this->requestId);

        $user = auth()->user();
        if (! $user || ! $request->isAccessibleBy($user)) {
            $this->addError('name', 'Только assigned-менеджер, делегат или РОП может править позиции.');

            return;
        }
        if ($user->hasRole('secretary')) {
            $this->addError('name', 'Секретарь только просматривает.');

            return;
        }

        $data = $this->validate();

        $item = $editor->addManual($request, [
            'name' => $data['name'],
            'brand' => $data['brand'] !== '' ? $data['brand'] : null,
            'article' => $data['article'] !== '' ? $data['article'] : null,
            'qty' => $data['qty'],
            'unit' => $data['unit'] !== '' ? $data['unit'] : null,
            'note' => $data['note'] !== '' ? $data['note'] : null,
        ]);

        // Заполнено по M-артикулу — позиция сразу в каталоге, как при ручной привязке в карточке.
        $linked = null;
        if ($this->catalogItemId !== null) {
            $catalog = CatalogItem::query()->whereKey($this->catalogItemId)->where('is_active', true)->first();
            if ($catalog !== null) {
                $editor->linkToCatalog($item, $catalog, $user);
                $linked = $catalog->sku;
            }
        }

        $this->resetForm();
        $this->expanded = false;

        session()->flash('status', $linked ? "Позиция добавлена и привязана к {$linked}." : 'Позиция добавлена.');

        // Родительский Detail слушает items-changed и перерисует таб
        // «Позиции» (список + счётчик + футер-итоги).
        $this->dispatch('items-changed');
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'brand', 'article', 'note', 'sku', 'catalogItemId']);
        $this->qty = '1';
        $this->unit = 'шт.';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.requests.items.add-item-form');
    }
}
