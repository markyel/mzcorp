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

    /**
     * Выбранные вложения (в порядке отображения). Несколько фото на позицию.
     *
     * @var array<int, int>
     */
    public array $selectedIds = [];

    /** Главное фото (id вложения); обязано входить в selectedIds. */
    public ?int $mainId = null;

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
            ->with('photos:id')
            ->first(['id', 'image_attachment_id']);
        if (! $item) {
            return;
        }
        $this->requestItemId = $item->id;

        $selected = $item->photos->pluck('id')->map(fn ($v) => (int) $v)->all();
        $main = $item->photos->first(fn ($p) => (bool) ($p->pivot->is_main ?? false))?->id;

        // Tolerant: позиция из парсера держит главное фото только в
        // image_attachment_id, без pivot-строк. Подставляем его как набор.
        if (empty($selected) && $item->image_attachment_id) {
            $selected = [(int) $item->image_attachment_id];
        }

        $this->selectedIds = $selected;
        $this->mainId = $main
            ? (int) $main
            : ($item->image_attachment_id ? (int) $item->image_attachment_id : ($selected[0] ?? null));
        if ($this->mainId !== null && ! in_array($this->mainId, $this->selectedIds, true)) {
            $this->mainId = $this->selectedIds[0] ?? null;
        }

        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->requestItemId = null;
        $this->selectedIds = [];
        $this->mainId = null;
    }

    /**
     * Тогл выбора фото. Снятие главного переносит «главную» на первое
     * оставшееся; добавление первого фото делает его главным.
     */
    public function toggleAttachment(int $attachmentId): void
    {
        if (in_array($attachmentId, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_filter(
                $this->selectedIds,
                fn ($v) => (int) $v !== $attachmentId,
            ));
            if ($this->mainId === $attachmentId) {
                $this->mainId = $this->selectedIds[0] ?? null;
            }
        } else {
            $this->selectedIds[] = $attachmentId;
            if ($this->mainId === null) {
                $this->mainId = $attachmentId;
            }
        }
    }

    /** Назначить фото главным (заодно включает его в набор). */
    public function setMain(int $attachmentId): void
    {
        if (! in_array($attachmentId, $this->selectedIds, true)) {
            $this->selectedIds[] = $attachmentId;
        }
        $this->mainId = $attachmentId;
    }

    /** Снять все привязки. */
    public function clearAll(): void
    {
        $this->selectedIds = [];
        $this->mainId = null;
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
     * Фото-вложения входящих писем треда заявки (триггерное + reply'и
     * клиента), пригодные для привязки: только реальные вложения, без
     * inline-картинок подписи (см. EmailAttachment::scopeBindablePhotos).
     *
     * Сортировка по (email_message_id, id) держит фото триггерного письма
     * первыми в Vision-порядке, затем — из более поздних писем. Дедуп по
     * (filename, size_bytes) схлопывает одинаковые вложения, присланные
     * в нескольких письмах треда.
     *
     * @return \Illuminate\Support\Collection<int, EmailAttachment>
     */
    #[Computed]
    public function photoAttachments()
    {
        $request = \App\Models\Request::query()
            ->whereKey($this->requestId)
            ->first(['id', 'email_message_id']);
        if (! $request) {
            return collect();
        }

        $messageIds = $request->photoSourceMessageIds();
        if (empty($messageIds)) {
            return collect();
        }

        return EmailAttachment::query()
            ->whereIn('email_message_id', $messageIds)
            ->bindablePhotos()
            ->orderBy('email_message_id')
            ->orderBy('id')
            ->get(['id', 'filename', 'mime_type', 'size_bytes'])
            ->unique(fn (EmailAttachment $a) => $a->filename.'|'.$a->size_bytes)
            ->values();
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
            $editor->syncPhotos($item, $this->selectedIds, $this->mainId, auth()->user());
        } catch (\DomainException $e) {
            $this->addError('selectedIds', $e->getMessage());

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
