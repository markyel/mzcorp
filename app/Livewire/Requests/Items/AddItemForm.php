<?php

namespace App\Livewire\Requests\Items;

use App\Models\Request as RequestModel;
use App\Services\Request\RequestItemEditor;
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

        $editor->addManual($request, [
            'name' => $data['name'],
            'brand' => $data['brand'] !== '' ? $data['brand'] : null,
            'article' => $data['article'] !== '' ? $data['article'] : null,
            'qty' => $data['qty'],
            'unit' => $data['unit'] !== '' ? $data['unit'] : null,
            'note' => $data['note'] !== '' ? $data['note'] : null,
        ]);

        $this->resetForm();
        $this->expanded = false;

        session()->flash('status', 'Позиция добавлена.');

        // Родительский Detail слушает items-changed и перерисует таб
        // «Позиции» (список + счётчик + футер-итоги).
        $this->dispatch('items-changed');
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'brand', 'article', 'note']);
        $this->qty = '1';
        $this->unit = 'шт.';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.requests.items.add-item-form');
    }
}
