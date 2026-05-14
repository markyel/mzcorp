<?php

namespace App\Livewire\Requests\Items;

use App\Models\EmailAttachment;
use App\Models\RequestItem;
use App\Services\Catalog\RequestItemEditor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal-диалог для перепривязки фото-вложения к позиции заявки
 * (Phase 2.4a, операторская правка Vision-mistake'ов).
 *
 * Vision-промпт image_index подбирает фото где товар *виден*, а не где он
 * *главный объект*. На общих планах одно фото может «прилипнуть» к двум-
 * трём позициям. Этот диалог даёт оператору grid из ВСЕХ image-вложений
 * письма с превью + кнопкой «без фото».
 *
 * Слушает `open-photo-rebind {itemId}`.
 *
 * Паттерн: хранит только `int $requestItemId`, не Eloquent-модель —
 * иначе Livewire-дегидратация конфликтует со shadow-import
 * Illuminate\Http\Request (Phase 1.13 грабля).
 */
class ItemPhotoRebindDialog extends Component
{
    public int $requestId;
    public ?int $requestItemId = null;
    public ?int $selectedAttachmentId = null;
    public bool $open = false;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    #[On('open-photo-rebind')]
    public function openForItem(int $itemId): void
    {
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($itemId)
            ->first(['id', 'image_attachment_id']);
        if (! $item) {
            return;
        }
        $this->requestItemId = $item->id;
        $this->selectedAttachmentId = $item->image_attachment_id;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->requestItemId = null;
        $this->selectedAttachmentId = null;
    }

    public function selectAttachment(?int $attachmentId): void
    {
        $this->selectedAttachmentId = $attachmentId;
    }

    /**
     * Текущая позиция заявки (для шапки modal'а — оператор видит
     * к какой позиции привязывает фото).
     */
    #[Computed]
    public function subjectItem(): ?RequestItem
    {
        if (! $this->requestItemId) {
            return null;
        }

        return RequestItem::query()
            ->with(['imageAttachment:id,filename,mime_type'])
            ->where('request_id', $this->requestId)
            ->whereKey($this->requestItemId)
            ->first(['id', 'position', 'parsed_name', 'parsed_article', 'parsed_brand', 'image_attachment_id']);
    }

    /**
     * Все image-вложения письма заявки в порядке id ASC.
     * Эта же последовательность — та, которую видел Vision.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EmailAttachment>
     */
    #[Computed]
    public function photoAttachments()
    {
        $item = $this->subjectItem;
        $emailMessageId = $item?->request?->email_message_id
            ?? \App\Models\Request::query()
                ->whereKey($this->requestId)
                ->value('email_message_id');

        if (! $emailMessageId) {
            return collect();
        }

        return EmailAttachment::query()
            ->where('email_message_id', $emailMessageId)
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('id')
            ->get(['id', 'filename', 'mime_type', 'size_bytes']);
    }

    public function save(RequestItemEditor $editor): void
    {
        if (! $this->requestItemId) {
            $this->close();

            return;
        }
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($this->requestItemId)
            ->first();
        if (! $item) {
            $this->close();

            return;
        }

        try {
            $editor->rebindPhoto($item, $this->selectedAttachmentId, auth()->user());
        } catch (\DomainException $e) {
            $this->addError('selectedAttachmentId', $e->getMessage());

            return;
        }

        $this->dispatch('item-photo-rebound');
        $this->close();
    }

    public function render()
    {
        return view('livewire.requests.items.item-photo-rebind-dialog');
    }
}
