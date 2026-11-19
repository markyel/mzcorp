<?php

namespace App\Livewire\Requests;

use App\Models\EmailAttachment;
use App\Models\Request;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка заявки → «Позиции»: файлы письма заявки, из которых парсер берёт позиции. Менеджер снимает галочку с файла,
 * где позиций нет («счёт по служебной записке», остальные документы — уведомления, реквизиты: MZ2026-02199,
 * 20.11.2026), и перечитывает позиции — парсер такие файлы пропускает (RequestItemParsingService). Перечитать —
 * существующий перезапуск парсера карточки (Detail::reparseItems, событие reparse-items).
 */
class ItemSourceFiles extends Component
{
    public int $requestId;

    /** Галочки поменяли, а позиции ещё не перечитаны. */
    public bool $changed = false;

    /** @return Collection<int, EmailAttachment> вложения письма заявки (без картинок из подписи) */
    #[Computed]
    public function files(): Collection
    {
        $emailId = Request::query()->whereKey($this->requestId)->value('email_message_id');

        return $emailId === null ? collect() : EmailAttachment::query()->where('email_message_id', $emailId)
            ->where(fn ($q) => $q->where('is_inline', false)->orWhereNull('is_inline'))->orderBy('id')->get();
    }

    public function toggle(int $attachmentId): void
    {
        abort_unless(Request::find($this->requestId)?->isAccessibleBy(auth()->user()), 403);
        $file = $this->files->firstWhere('id', $attachmentId);
        if ($file === null) {
            return;
        }
        $file->forceFill($file->items_ignored_at === null
            ? ['items_ignored_at' => now(), 'items_ignored_by_user_id' => auth()->id()]
            : ['items_ignored_at' => null, 'items_ignored_by_user_id' => null])->save();
        unset($this->files);
        $this->changed = true;
    }

    public function reparse(): void
    {
        $this->changed = false;
        $this->dispatch('reparse-items');
    }

    public function render()
    {
        return view('livewire.requests.item-source-files');
    }
}
