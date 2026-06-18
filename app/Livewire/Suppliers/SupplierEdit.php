<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use App\Services\Supplier\SupplierMatrixBuilder;
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
    public string $assortment_description = '';
    public string $notes = '';

    public bool $confirmingDelete = false;

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
        $this->assortment_description = (string) ($s->assortment_description ?? '');
        $this->notes = (string) ($s->notes ?? '');
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
