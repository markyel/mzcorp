<?php

namespace App\Livewire\Admin\Updates;

use App\Models\ChangelogEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Управление разделом «Обновления» (privileged: head_of_sales/director/admin).
 * Список всех записей (вкл. черновики), публикация/снятие, удаление.
 */
class Index extends Component
{
    public function mount(): void
    {
        $this->ensureCanManage();
    }

    private function ensureCanManage(): void
    {
        abort_unless(
            (bool) Auth::user()?->hasAnyRole(['head_of_sales', 'director', 'admin']),
            403,
        );
    }

    #[Computed]
    public function entries()
    {
        return ChangelogEntry::query()
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get();
    }

    public function togglePublish(int $id): void
    {
        $this->ensureCanManage();

        $entry = ChangelogEntry::findOrFail($id);
        if ($entry->is_published) {
            $entry->forceFill(['is_published' => false])->save();
            session()->flash('status', "«{$entry->title}» снята с публикации.");
        } else {
            $entry->forceFill([
                'is_published' => true,
                // Дата публикации проставляется при ПЕРВОЙ публикации и далее
                // не сдвигается (повторная публикация не меняет порядок ленты).
                'published_at' => $entry->published_at ?? now(),
            ])->save();
            session()->flash('status', "«{$entry->title}» опубликована.");
        }

        unset($this->entries);
    }

    public function delete(int $id): void
    {
        $this->ensureCanManage();

        $entry = ChangelogEntry::findOrFail($id);
        $title = $entry->title;
        $entry->delete();

        session()->flash('status', "«{$title}» удалена.");
        unset($this->entries);
    }

    public function render()
    {
        return view('livewire.admin.updates.index');
    }
}
