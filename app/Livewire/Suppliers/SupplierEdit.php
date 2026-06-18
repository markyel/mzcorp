<?php

namespace App\Livewire\Suppliers;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use App\Models\Supplier;
use App\Services\Supplier\SupplierMatrixBuilder;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка поставщика из реестра (Фаза 3.1): реквизиты + описание ассортимента
 * + матрица «бренд/категория» (строит AI) для подбора под позицию. Доступ —
 * все роли (как «Клиенты»/«Поставщики»).
 */
class SupplierEdit extends Component
{
    public Supplier $supplier;

    public string $name = '';
    public string $email = '';
    public string $domain = '';
    public string $phone = '';
    public string $language = 'ru';
    public string $assortment_description = '';
    public string $notes = '';

    /** Ручные правила подбора с wildcard «ВСЕ»: [{brand, category}]. */
    public array $rules = [];

    public string $newRuleBrand = 'ВСЕ';
    public string $newRuleCategory = 'ВСЕ';

    public bool $confirmingDelete = false;

    public const ALL = 'ВСЕ';

    public function mount(Supplier $supplier): void
    {
        abort_unless(auth()->check(), 403);
        $this->supplier = $supplier;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $s = $this->supplier;
        $this->name = (string) ($s->name ?? '');
        $this->email = (string) ($s->email ?? '');
        $this->domain = (string) ($s->domain ?? '');
        $this->phone = (string) ($s->phone ?? '');
        $this->language = in_array($s->language, ['ru', 'en'], true) ? $s->language : 'ru';
        $this->assortment_description = (string) ($s->assortment_description ?? '');
        $this->notes = (string) ($s->notes ?? '');
        $matrix = is_array($s->assortment_matrix) ? $s->assortment_matrix : [];
        $this->rules = array_values(array_filter((array) ($matrix['rules'] ?? []), 'is_array'));
    }

    /** @return array<int, string> */
    #[Computed]
    public function brandOptions(): array
    {
        return array_merge([self::ALL], ManufacturerBrand::query()->orderBy('name')->pluck('name')->all());
    }

    /** @return array<int, string> */
    #[Computed]
    public function categoryOptions(): array
    {
        return array_merge([self::ALL], EquipmentCategory::query()->orderBy('name')->pluck('name')->all());
    }

    public function addRule(): void
    {
        $b = trim($this->newRuleBrand) ?: self::ALL;
        $c = trim($this->newRuleCategory) ?: self::ALL;
        if ($b === self::ALL && $c === self::ALL) {
            // {ВСЕ, ВСЕ} допустимо — поставщик-«универсал».
        }
        // Дедуп.
        foreach ($this->rules as $r) {
            if (($r['brand'] ?? '') === $b && ($r['category'] ?? '') === $c) {
                return;
            }
        }
        $this->rules[] = ['brand' => $b, 'category' => $c];
        $this->persistRules();
        $this->newRuleBrand = self::ALL;
        $this->newRuleCategory = self::ALL;
    }

    public function removeRule(int $idx): void
    {
        if (isset($this->rules[$idx])) {
            unset($this->rules[$idx]);
            $this->rules = array_values($this->rules);
            $this->persistRules();
        }
    }

    private function persistRules(): void
    {
        $matrix = is_array($this->supplier->assortment_matrix) ? $this->supplier->assortment_matrix : [];
        $matrix['rules'] = array_values($this->rules);
        $this->supplier->forceFill(['assortment_matrix' => $matrix])->save();
        $this->dispatch('toast', message: 'Правила подбора обновлены.', type: 'success');
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'domain' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:64',
            'assortment_description' => 'nullable|string|max:6000',
            'notes' => 'nullable|string|max:5000',
        ], [], ['email' => 'email', 'domain' => 'домен']);

        $email = mb_strtolower(trim($this->email));
        $domain = ltrim(mb_strtolower(trim($this->domain)), '@');
        if ($email === '' && $domain === '') {
            $this->addError('email', 'Укажите email или домен.');

            return;
        }

        $this->supplier->update([
            'name' => trim($this->name) !== '' ? trim($this->name) : null,
            'email' => $email !== '' ? $email : null,
            'domain' => $domain !== '' ? $domain : null,
            'phone' => trim($this->phone) !== '' ? trim($this->phone) : null,
            'language' => in_array($this->language, ['ru', 'en'], true) ? $this->language : 'ru',
            'assortment_description' => trim($this->assortment_description) !== '' ? trim($this->assortment_description) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->dispatch('toast', message: 'Сохранено.', type: 'success');
    }

    public function rebuildMatrix(SupplierMatrixBuilder $builder): void
    {
        // Сохраняем описание перед сборкой, чтобы матрица отражала актуальный текст.
        $this->supplier->update([
            'assortment_description' => trim($this->assortment_description) !== '' ? trim($this->assortment_description) : null,
        ]);

        $ok = $builder->rebuild($this->supplier->fresh());
        $this->supplier->refresh();

        $this->dispatch(
            'toast',
            message: $ok ? 'Матрица ассортимента пересобрана.' : 'Не удалось собрать матрицу (LLM недоступен) — попробуйте позже.',
            type: $ok ? 'success' : 'error',
        );
    }

    public function deleteSupplier()
    {
        $this->supplier->delete();

        return $this->redirectRoute('suppliers.index', ['tab' => 'registry'], navigate: true);
    }

    public function render()
    {
        return view('livewire.suppliers.supplier-edit');
    }
}
