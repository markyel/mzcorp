<?php

namespace App\Services\Mail;

use App\Models\LetterTemplate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * CRUD и перемещение узлов дерева шаблонов писем.
 *
 * Тонкие Livewire-компоненты → логика дерева (guard от циклов, sort_order,
 * «сохранить письмо как шаблон») живёт здесь.
 */
class LetterTemplateService
{
    /**
     * @param  array{parent_id?: ?int, is_folder?: bool, name: string, subject?: ?string, body?: ?string}  $data
     */
    public function create(array $data, ?User $by = null): LetterTemplate
    {
        $parentId = $data['parent_id'] ?? null;

        return LetterTemplate::create([
            'parent_id' => $parentId,
            'is_folder' => (bool) ($data['is_folder'] ?? false),
            'name' => $data['name'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'sort_order' => $this->nextSortOrder($parentId),
            'created_by_user_id' => $by?->id,
            'updated_by_user_id' => $by?->id,
        ]);
    }

    /**
     * @param  array{name?: string, subject?: ?string, body?: ?string}  $data
     */
    public function update(LetterTemplate $node, array $data, ?User $by = null): LetterTemplate
    {
        if (isset($data['name'])) {
            $node->name = $data['name'];
        }
        if (array_key_exists('subject', $data)) {
            $node->subject = $data['subject'];
        }
        if (array_key_exists('body', $data)) {
            $node->body = $data['body'];
        }
        $node->updated_by_user_id = $by?->id;
        $node->save();

        return $node;
    }

    /**
     * Переместить узел в другую папку (или в корень при null).
     * Guard от циклов: нельзя сделать узел потомком самого себя.
     */
    public function move(LetterTemplate $node, ?int $newParentId): void
    {
        if ($newParentId === $node->id) {
            return; // нельзя быть родителем самому себе
        }
        if ($newParentId !== null && $this->isDescendantOf($newParentId, $node->id)) {
            return; // новый родитель находится внутри перемещаемого поддерева
        }
        $node->parent_id = $newParentId;
        $node->sort_order = $this->nextSortOrder($newParentId);
        $node->save();
    }

    /**
     * Поменять местами с соседом (для кнопок вверх/вниз).
     */
    public function reorder(LetterTemplate $node, int $newSortOrder): void
    {
        $node->sort_order = $newSortOrder;
        $node->save();
    }

    public function delete(LetterTemplate $node): void
    {
        $node->delete(); // поддерево уносит FK cascadeOnDelete
    }

    /**
     * Сохранить текущее письмо composer'а как шаблон-лист.
     */
    public function saveFromLetter(
        string $name,
        string $body,
        ?int $parentId,
        ?string $subject,
        ?User $by = null,
    ): LetterTemplate {
        return $this->create([
            'parent_id' => $parentId,
            'is_folder' => false,
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
        ], $by);
    }

    /**
     * Всё дерево от корней (с рекурсивно подгруженными детьми).
     *
     * @return Collection<int, LetterTemplate>
     */
    public function tree(): Collection
    {
        return LetterTemplate::roots()
            ->with('childrenRecursive')
            ->orderByDesc('is_folder')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function nextSortOrder(?int $parentId): int
    {
        $max = LetterTemplate::query()
            ->when($parentId === null,
                fn ($q) => $q->whereNull('parent_id'),
                fn ($q) => $q->where('parent_id', $parentId),
            )
            ->max('sort_order');

        return (int) $max + 1;
    }

    /**
     * $candidateId является потомком $ancestorId? (обход вверх по родителям).
     */
    private function isDescendantOf(int $candidateId, int $ancestorId): bool
    {
        $current = LetterTemplate::find($candidateId);
        $guard = 0;
        while ($current !== null && $guard++ < 1000) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }
            if ($current->parent_id === null) {
                return false;
            }
            $current = LetterTemplate::find($current->parent_id);
        }

        return false;
    }
}
