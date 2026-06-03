<?php

namespace App\Livewire\Requests;

use App\Enums\MailDirection;
use App\Enums\Role as RoleEnum;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Models\User;
use App\Services\Request\RequestSplitService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Модалка ручного разъединения заявки (Split / un-merge).
 *
 * Доступна admin/director/РОП. Открывается из карточки заявки. Админ выбирает
 * письма ЧУЖОГО потока + их позиции (предвыбираются по провенансу
 * source_email_message_id, но правятся вручную — гибрид) и выносит их в новую
 * заявку с авто-распределением или конкретным менеджером.
 *
 * После save() → RequestSplitService::split() → dispatch('request-state-changed')
 * родительский Detail перерисует карточку.
 *
 * Храним только requestId (не Eloquent-модель): Livewire-дегидратация
 * App\Models\Request ловит shadow от Illuminate\Http\Request → 500.
 */
class SplitDialog extends Component
{
    public int $requestId;

    public bool $open = false;

    /** @var array<int> id выбранных писем */
    public array $selectedEmailIds = [];

    /** @var array<int> id выбранных позиций */
    public array $selectedItemIds = [];

    /** 'auto' | 'manager' */
    public string $assignMode = 'auto';

    public ?int $assignToUserId = null;

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    private function request(): RequestModel
    {
        return RequestModel::findOrFail($this->requestId);
    }

    public function show(): void
    {
        $this->selectedEmailIds = [];
        $this->selectedItemIds = [];
        $this->assignMode = 'auto';
        $this->assignToUserId = null;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * При изменении набора писем — предвыбираем их позиции по провенансу.
     * Гибрид: дальше админ может вручную снять/добавить галочки позиций.
     */
    public function updatedSelectedEmailIds(): void
    {
        $ids = array_map('intval', $this->selectedEmailIds);
        $this->selectedItemIds = $this->items
            ->whereIn('source_email_message_id', $ids)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();
    }

    public function save(RequestSplitService $service)
    {
        $this->ensureAuthorized();

        try {
            $result = $service->split(
                source: $this->request(),
                emailIds: array_map('intval', $this->selectedEmailIds),
                itemIds: array_map('intval', $this->selectedItemIds),
                assignMode: $this->assignMode,
                assignToUserId: $this->assignMode === 'manager' ? $this->assignToUserId : null,
                by: auth()->user(),
            );
        } catch (\DomainException $e) {
            $this->addError('split', $e->getMessage());

            return null;
        }

        $this->open = false;
        $assignedTo = $result['assigned_to'] ?? null;
        session()->flash('status', sprintf(
            'Выделена заявка %s · позиций: %d · писем: %d%s',
            $result['new_internal_code'],
            $result['items_moved'],
            $result['emails_moved'],
            $assignedTo ? ' · менеджер: ' . $assignedTo : '',
        ));

        $this->dispatch('request-state-changed');

        return null;
    }

    private function ensureAuthorized(): void
    {
        $user = auth()->user();
        $allowed = $user && $user->hasAnyRole([
            RoleEnum::Admin->value,
            RoleEnum::Director->value,
            RoleEnum::HeadOfSales->value,
        ]);
        if (! $allowed) {
            abort(403);
        }
    }

    /**
     * Письма заявки (тред) — для чекбоксов. Сортировка как в карточке.
     */
    #[Computed]
    public function thread()
    {
        return EmailMessage::query()
            ->where('related_request_id', $this->requestId)
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->orderByRaw('sent_at IS NULL, sent_at ASC')
            ->orderBy('id')
            ->get(['id', 'direction', 'from_email', 'from_name', 'subject', 'sent_at', 'mailbox_id']);
    }

    /**
     * Активные позиции заявки с провенансом (source_email_message_id).
     */
    #[Computed]
    public function items()
    {
        return RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->orderBy('position')
            ->get(['id', 'position', 'parsed_name', 'parsed_qty', 'parsed_unit', 'source_email_message_id']);
    }

    /**
     * Карта email_id → количество позиций-источников (для бейджа у письма).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function itemCountByEmail(): array
    {
        return $this->items
            ->groupBy('source_email_message_id')
            ->map->count()
            ->toArray();
    }

    #[Computed]
    public function managers()
    {
        return User::query()
            ->role(RoleEnum::requestHandlerRoles())
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function render()
    {
        return view('livewire.requests.split-dialog', [
            'request' => $this->request(),
        ]);
    }
}
