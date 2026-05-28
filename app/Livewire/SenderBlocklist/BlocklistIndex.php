<?php

namespace App\Livewire\SenderBlocklist;

use App\Enums\BlocklistEntrySource;
use App\Enums\BlocklistEntryType;
use App\Models\SenderBlocklistEntry;
use App\Services\Mail\SenderBlocklistService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Управление стоп-листом отправителей.
 *
 * Доступ: head_of_sales/director/admin (через middleware role в routes).
 * Менеджер сам в стоп-лист попадает только из карточки заявки через
 * action «Закрыть как спам» — CRUD ему недоступен.
 *
 * UI:
 *   - Поиск/фильтр (по значению, типу, источнику).
 *   - Inline-форма «добавить» — single или bulk (textarea построчно,
 *     каждая строка распознаётся как email или domain автоматически).
 *   - Удаление одной кнопкой с confirm.
 */
class BlocklistIndex extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'src', except: '')]
    public string $sourceFilter = '';

    public bool $showAddForm = false;
    public bool $bulkMode = false;

    #[Validate('required_without:bulkValues|nullable|string|max:255')]
    public string $singleValue = '';

    public string $singleType = 'email';

    #[Validate('required_with_all:bulkMode|nullable|string|max:10000')]
    public string $bulkValues = '';

    #[Validate('nullable|string|max:500')]
    public string $comment = '';

    public ?string $flashMessage = null;
    public ?string $flashError = null;

    #[Computed]
    public function entries()
    {
        $q = SenderBlocklistEntry::query()->with(['addedBy', 'addedFromRequest']);

        $needle = trim($this->search);
        if ($needle !== '') {
            $like = '%'.mb_strtolower($needle).'%';
            $q->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(value) LIKE ?', [$like])
                    ->orWhere('normalized_value', 'LIKE', $like);
            });
        }

        if ($this->typeFilter !== '') {
            $q->where('type', $this->typeFilter);
        }
        if ($this->sourceFilter !== '') {
            $q->where('source', $this->sourceFilter);
        }

        return $q->orderByDesc('created_at')->limit(500)->get();
    }

    #[Computed]
    public function totalCount(): int
    {
        return SenderBlocklistEntry::query()->count();
    }

    public function toggleAddForm(): void
    {
        $this->showAddForm = ! $this->showAddForm;
        $this->resetForm();
    }

    public function add(SenderBlocklistService $svc): void
    {
        $this->flashMessage = null;
        $this->flashError = null;

        $user = auth()->user();

        try {
            if ($this->bulkMode) {
                $lines = preg_split('/[\r\n]+/', $this->bulkValues) ?: [];
                $result = $svc->bulkBlock(
                    $lines,
                    BlocklistEntrySource::Manual,
                    $user,
                    $this->comment !== '' ? $this->comment : null,
                );

                $parts = ["Добавлено: {$result['created']}"];
                if ($result['skipped'] > 0) {
                    $parts[] = "уже было: {$result['skipped']}";
                }
                if (! empty($result['invalid'])) {
                    $parts[] = 'не распознано: '.count($result['invalid']);
                    $this->flashError = 'Строки не распознаны: '.implode(', ', array_slice($result['invalid'], 0, 5))
                        .(count($result['invalid']) > 5 ? '…' : '');
                }
                $this->flashMessage = implode(', ', $parts).'.';
            } else {
                $type = BlocklistEntryType::tryFrom($this->singleType) ?? BlocklistEntryType::Email;
                $entry = $svc->block(
                    $this->singleValue,
                    $type,
                    BlocklistEntrySource::Manual,
                    $user,
                    null,
                    $this->comment !== '' ? $this->comment : null,
                );
                $this->flashMessage = $entry->wasRecentlyCreated
                    ? "Добавлено: {$entry->value}"
                    : "Уже было в стоп-листе: {$entry->value}";
            }

            $this->resetForm();
            $this->showAddForm = false;
            unset($this->entries, $this->totalCount);
        } catch (\InvalidArgumentException $e) {
            $this->flashError = $e->getMessage();
        }
    }

    public function delete(int $id, SenderBlocklistService $svc): void
    {
        $entry = SenderBlocklistEntry::find($id);
        if ($entry) {
            $svc->unblock($id);
            $this->flashMessage = "Удалено из стоп-листа: {$entry->value}";
            unset($this->entries, $this->totalCount);
        }
    }

    private function resetForm(): void
    {
        $this->singleValue = '';
        $this->bulkValues = '';
        $this->comment = '';
        $this->singleType = 'email';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.sender-blocklist.blocklist-index');
    }
}
