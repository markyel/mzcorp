<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Models\Request as RequestModel;
use App\Services\Request\RequestMergeService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal-диалог для слияния заявки-дубликата (loser) в текущую (winner).
 *
 * Открывается из action-panel кнопкой «⊌ Слить с дубликатом» — слушает
 * `open-merge-dialog`. Mounted в Detail (winner = $this->request).
 *
 * Поведение:
 *  - Поиск кандидатов: все active Request с тем же client_email (case-i),
 *    исключая winner. Показываем code + создание + items_count + сводку.
 *  - Выбор кандидата → preview статистики (items_to_add/skip, emails, batches).
 *  - Кнопка «Слить» → RequestMergeService::merge → flash + navigate в winner.
 */
class MergeDialog extends Component
{
    public int $requestId;
    public bool $open = false;
    public ?int $selectedLoserId = null;
    public string $search = '';

    private const ACTIVE_STATUSES = [
        RequestStatus::New,
        RequestStatus::Assigned,
        RequestStatus::InProgress,
        RequestStatus::AwaitingClientClarification,
        RequestStatus::Quoted,
        RequestStatus::UnderReview,
        RequestStatus::PostponedUntil,
        RequestStatus::AwaitingInvoice,
        RequestStatus::Invoiced,
    ];

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-merge-dialog')]
    public function show(): void
    {
        $this->selectedLoserId = null;
        $this->search = '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->selectedLoserId = null;
    }

    public function selectLoser(int $id): void
    {
        $this->selectedLoserId = $id;
    }

    /**
     * Кандидаты на слияние: active Request с тем же client_email.
     */
    #[Computed]
    public function candidates()
    {
        $winner = $this->winner();
        if ($winner === null || $winner->client_email === '') {
            return collect();
        }

        $activeValues = array_map(fn (RequestStatus $s) => $s->value, self::ACTIVE_STATUSES);

        $q = RequestModel::query()
            ->where('id', '!=', $winner->id)
            ->whereRaw('LOWER(client_email) = ?', [mb_strtolower(trim($winner->client_email))])
            ->whereIn('status', $activeValues)
            ->withCount(['items' => fn ($q) => $q->where('is_active', true)])
            ->with('assignedUser:id,name')
            ->orderByDesc('created_at')
            ->limit(20);

        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('internal_code', 'ilike', $needle)
                    ->orWhere('subject', 'ilike', $needle);
            });
        }

        return $q->get(['id', 'internal_code', 'subject', 'status', 'assigned_user_id', 'created_at', 'client_email']);
    }

    /**
     * @return array{items_to_add: int, items_to_skip: int, emails_to_move: int, batches_to_move: int, conflicts: array<int, string>}|null
     */
    #[Computed]
    public function previewStats(): ?array
    {
        if ($this->selectedLoserId === null) {
            return null;
        }
        $loser = RequestModel::find($this->selectedLoserId);
        $winner = $this->winner();
        if ($loser === null || $winner === null) {
            return null;
        }

        return app(RequestMergeService::class)->preview($winner, $loser);
    }

    public function confirmMerge(RequestMergeService $service): void
    {
        $winner = $this->winner();
        $loser = $this->selectedLoserId ? RequestModel::find($this->selectedLoserId) : null;
        if ($winner === null || $loser === null) {
            $this->addError('selectedLoserId', 'Выберите заявку для слияния.');

            return;
        }

        try {
            $stats = $service->merge($winner, $loser, auth()->user());
        } catch (\Throwable $e) {
            $this->addError('selectedLoserId', $e->getMessage());

            return;
        }

        session()->flash('status', sprintf(
            'Заявка %s слита в эту. Перенесено: позиций +%d (пропущено %d), писем %d, уточнений %d.',
            $loser->internal_code,
            $stats['items_added'],
            $stats['items_skipped'],
            $stats['emails_moved'],
            $stats['batches_moved'],
        ));

        $this->open = false;
        $this->selectedLoserId = null;
        $this->dispatch('request-state-changed');
    }

    private function winner(): ?RequestModel
    {
        return RequestModel::find($this->requestId);
    }

    public function render()
    {
        return view('livewire.requests.merge-dialog');
    }
}
