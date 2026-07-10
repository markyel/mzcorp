<?php

namespace App\Livewire\LetterTemplates;

use App\Enums\Role as RoleEnum;
use App\Models\LetterTemplate;
use App\Services\Mail\LetterTemplateService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Управление деревом шаблонов писем (страница /dashboard/letter-templates).
 *
 * Дерево слишком богато для модалки → отдельная страница. Один самодостаточный
 * компонент с inline-редактором (по образцу RuleList/RuleEditor).
 *
 * Библиотека ОБЩАЯ (shared): создавать/редактировать может любой request-handler
 * (manager/РОП) + admin. created_by/updated_by пишем для аудита.
 */
class TemplateManager extends Component
{
    // Состояние inline-редактора.
    public ?int $editingId = null;      // null + $creating=false → форма скрыта
    public bool $creating = false;
    public bool $isFolder = false;
    public ?int $parentId = null;

    #[Validate('required|string|min:2|max:160')]
    public string $name = '';

    #[Validate('nullable|string|max:998')]
    public ?string $subject = null;

    #[Validate('nullable|string|max:20000')]
    public ?string $body = null;

    #[Computed]
    public function tree()
    {
        return app(LetterTemplateService::class)->tree();
    }

    /**
     * Плоский список папок для селекта «переместить в…» / выбора родителя.
     *
     * @return \Illuminate\Support\Collection<int, LetterTemplate>
     */
    #[Computed]
    public function folders()
    {
        return LetterTemplate::folders()->orderBy('name')->get(['id', 'name', 'parent_id']);
    }

    public function newTemplate(?int $parentId = null): void
    {
        $this->ensureCanEdit();
        $this->resetForm();
        $this->creating = true;
        $this->isFolder = false;
        $this->parentId = $parentId;
    }

    public function newFolder(?int $parentId = null): void
    {
        $this->ensureCanEdit();
        $this->resetForm();
        $this->creating = true;
        $this->isFolder = true;
        $this->parentId = $parentId;
    }

    public function edit(int $id): void
    {
        $this->ensureCanEdit();
        $node = LetterTemplate::findOrFail($id);
        $this->editingId = $node->id;
        $this->creating = false;
        $this->isFolder = (bool) $node->is_folder;
        $this->parentId = $node->parent_id;
        $this->name = $node->name;
        $this->subject = $node->subject;
        $this->body = $node->body;
        $this->resetErrorBag();
    }

    public function save(LetterTemplateService $service)
    {
        $this->ensureCanEdit();
        $this->validate();

        if (! $this->isFolder && trim((string) $this->body) === '') {
            $this->addError('body', 'У шаблона должно быть тело письма.');

            return null;
        }

        if ($this->editingId) {
            $node = LetterTemplate::findOrFail($this->editingId);
            $service->update($node, [
                'name' => $this->name,
                'subject' => $this->isFolder ? null : ($this->subject ?: null),
                'body' => $this->isFolder ? null : $this->body,
            ], auth()->user());
        } else {
            $service->create([
                'parent_id' => $this->parentId,
                'is_folder' => $this->isFolder,
                'name' => $this->name,
                'subject' => $this->isFolder ? null : ($this->subject ?: null),
                'body' => $this->isFolder ? null : $this->body,
            ], auth()->user());
        }

        $this->resetForm();
        session()->flash('status', 'Сохранено.');
        unset($this->tree, $this->folders);

        return null;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id, LetterTemplateService $service): void
    {
        $this->ensureCanEdit();
        $node = LetterTemplate::find($id);
        if ($node) {
            $service->delete($node);
        }
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        unset($this->tree, $this->folders);
    }

    public function moveUp(int $id, LetterTemplateService $service): void
    {
        $this->swapWithSibling($id, -1, $service);
    }

    public function moveDown(int $id, LetterTemplateService $service): void
    {
        $this->swapWithSibling($id, +1, $service);
    }

    public function moveTo(int $id, ?int $newParentId, LetterTemplateService $service): void
    {
        $this->ensureCanEdit();
        $node = LetterTemplate::find($id);
        if ($node) {
            $service->move($node, $newParentId);
        }
        unset($this->tree, $this->folders);
    }

    /**
     * Поменять узел местами с соседом по sort_order (±1 позиция).
     */
    private function swapWithSibling(int $id, int $direction, LetterTemplateService $service): void
    {
        $this->ensureCanEdit();
        $node = LetterTemplate::find($id);
        if (! $node) {
            return;
        }

        $siblings = LetterTemplate::query()
            ->when($node->parent_id === null,
                fn ($q) => $q->whereNull('parent_id'),
                fn ($q) => $q->where('parent_id', $node->parent_id),
            )
            ->orderByDesc('is_folder')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $index = $siblings->search(fn (LetterTemplate $s) => $s->id === $node->id);
        if ($index === false) {
            return;
        }
        $target = $siblings->get($index + $direction);
        // Меняемся только внутри той же группы (папки/шаблоны не перемешиваем).
        if (! $target || $target->is_folder !== $node->is_folder) {
            return;
        }

        $nodeOrder = (int) $node->sort_order;
        $service->reorder($node, (int) $target->sort_order);
        $service->reorder($target, $nodeOrder);
        unset($this->tree);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->creating = false;
        $this->isFolder = false;
        $this->parentId = null;
        $this->name = '';
        $this->subject = null;
        $this->body = null;
        $this->resetErrorBag();
    }

    public function canEdit(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasAnyRole([
            RoleEnum::Manager->value,
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
            RoleEnum::Admin->value,
        ]);
    }

    private function ensureCanEdit(): void
    {
        if (! $this->canEdit()) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.letter-templates.template-manager');
    }
}
