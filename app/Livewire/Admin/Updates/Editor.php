<?php

namespace App\Livewire\Admin\Updates;

use App\Models\ChangelogEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Создание / редактирование записи раздела «Обновления».
 * Доступ — privileged (head_of_sales/director/admin). Тело — markdown,
 * есть live-preview.
 */
class Editor extends Component
{
    public ?int $entryId = null;

    #[Validate('required|string|max:200')]
    public string $title = '';

    #[Validate('required|string')]
    public string $body = '';

    public bool $isPublished = false;

    public function mount(?ChangelogEntry $entry = null): void
    {
        $this->ensureCanManage();

        if ($entry && $entry->exists) {
            $this->entryId = $entry->id;
            $this->title = $entry->title;
            $this->body = $entry->body;
            $this->isPublished = (bool) $entry->is_published;
        }
    }

    private function ensureCanManage(): void
    {
        abort_unless(
            (bool) Auth::user()?->hasAnyRole(['head_of_sales', 'director', 'admin']),
            403,
        );
    }

    public function getPreviewHtmlProperty(): string
    {
        return trim($this->body) === ''
            ? ''
            : Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function save()
    {
        $this->ensureCanManage();
        $this->validate();

        if ($this->entryId) {
            $entry = ChangelogEntry::findOrFail($this->entryId);
            $entry->fill([
                'title' => $this->title,
                'body' => $this->body,
                'is_published' => $this->isPublished,
            ]);
            // published_at — при ПЕРВОЙ публикации; далее не сдвигаем.
            if ($this->isPublished && $entry->published_at === null) {
                $entry->published_at = now();
            }
            $entry->save();

            session()->flash('status', "«{$entry->title}» сохранена.");

            return $this->redirect(route('updates.manage'), navigate: true);
        }

        ChangelogEntry::create([
            'title' => $this->title,
            'body' => $this->body,
            'is_published' => $this->isPublished,
            'published_at' => $this->isPublished ? now() : null,
            'author_user_id' => Auth::id(),
        ]);

        session()->flash('status', 'Запись создана.');

        return $this->redirect(route('updates.manage'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.updates.editor');
    }
}
