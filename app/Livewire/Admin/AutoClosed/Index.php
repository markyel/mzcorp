<?php

namespace App\Livewire\Admin\AutoClosed;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Request\AssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Пул автозакрытых заявок (`assigned_user_id IS NULL` + `status = closed_lost`
 * + `closed_lost_reason = parser_no_content`).
 *
 * Видят: head_of_sales, director, admin, secretary.
 *
 * Откуда: `RequestsRecoverUnassignedCommand` (hourly cron) проходит по
 * Pending-заявкам без позиций старше threshold и через
 * `AutoCloseDecisionService` (gpt-4o-mini) решает close/keep. Сюда
 * попадают close-вердикты.
 *
 * Действие: «↻ Восстановить» переводит заявку из ClosedLost в Pending,
 * пишет audit и запускает `AssignmentService::autoAssign()` — заявка
 * получает менеджера и попадает в его пул как обычная.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Окно по времени: today / 7d / 30d / 90d / all.
     */
    #[Url(as: 'window')]
    public string $window = '30d';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingWindow(): void
    {
        $this->resetPage();
    }

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['today', '7d', '30d', '90d', 'all'], true) ? $window : '30d';
        $this->resetPage();
    }

    /**
     * Восстановить автозакрытую заявку: ClosedLost → Pending + autoAssign.
     *
     * Не используем `RequestStateService::reanimate` — он предназначен для
     * inbound reply'ев клиента в треде, ставит reanimated_at и трекает
     * счётчик реанимаций. Здесь — ручной revert операторского решения LLM,
     * это семантически другой случай (заявка реально новая, просто крон
     * её ошибочно закрыл). Делаем явный update + state_change.
     */
    public function restore(int $requestId, AssignmentService $assignment): void
    {
        $request = Request::find($requestId);
        if (! $request) {
            return;
        }
        if ($request->status !== RequestStatus::ClosedLost) {
            session()->flash('error', "Заявка {$request->internal_code} уже не в ClosedLost.");
            return;
        }
        if ($request->closed_lost_reason !== ClosedLostReason::ParserNoContent->value) {
            session()->flash('error', "Заявка {$request->internal_code} закрыта не как parser_no_content — восстанавливать тут нельзя.");
            return;
        }

        $fromStatus = $request->status;
        $userId = auth()->id();

        $request->forceFill([
            'status' => RequestStatus::Pending,
            'closed_at' => null,
            'closed_lost_reason' => null,
            'closed_lost_comment' => null,
        ])->save();

        RequestStateChange::create([
            'request_id' => $request->id,
            'from_status' => $fromStatus->value,
            'to_status' => RequestStatus::Pending->value,
            'by_user_id' => $userId,
            'event' => 'manual_restore_auto_closed',
            'comment' => sprintf(
                'Восстановлено вручную (%s): автозакрытие через LLM было ошибочным.',
                auth()->user()?->name ?? '(unknown)',
            ),
            'payload' => [
                'previous_close_event_payload' => RequestStateChange::where('request_id', $request->id)
                    ->where('event', 'system_close_lost')
                    ->latest('id')
                    ->first()?->payload,
            ],
        ]);

        // AutoAssign — менеджер появится, заявка попадёт в его пул как Pending.
        $manager = null;
        try {
            $manager = $assignment->autoAssign($request);
        } catch (\Throwable $e) {
            Log::error('AutoClosed restore: autoAssign threw', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('AutoClosed: request restored', [
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'restored_by_user_id' => $userId,
            'autoassigned_to' => $manager?->id,
        ]);

        session()->flash('status', $manager
            ? sprintf('Заявка %s восстановлена и назначена на %s.', $request->internal_code, $manager->name)
            : sprintf('Заявка %s восстановлена. autoAssign не нашёл доступного менеджера — заявка в Pending.', $request->internal_code));
    }

    #[Computed]
    public function requests()
    {
        return $this->buildQuery()
            ->with([
                'emailMessage:id,from_email,from_name,subject,sent_at,body_plain',
                'emailMessage.attachments:id,email_message_id,filename,mime_type',
            ])
            ->orderByDesc('closed_at')
            ->paginate(25);
    }

    #[Computed]
    public function totalCount(): int
    {
        return $this->buildQuery()->count();
    }

    private function buildQuery(): Builder
    {
        $q = Request::query()
            ->whereNull('assigned_user_id')
            ->where('status', RequestStatus::ClosedLost->value)
            ->where('closed_lost_reason', ClosedLostReason::ParserNoContent->value);

        $since = match ($this->window) {
            'today' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };
        if ($since !== null) {
            $q->where('closed_at', '>=', $since);
        }

        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('subject', 'ilike', $needle)
                    ->orWhere('client_email', 'ilike', $needle)
                    ->orWhere('internal_code', 'ilike', $needle);
            });
        }

        return $q;
    }

    /**
     * Подтянуть payload последнего system_close_lost state_change для каждой
     * заявки на странице. Используется в blade для отображения LLM reasoning.
     *
     * Делаем отдельным запросом по id'шникам, чтобы не тащить весь
     * state_changes-эадж в основной paginate'е (там обычно много шума).
     *
     * @return array<int, array{verdict?:string, confidence?:float, reasoning?:string}>
     */
    #[Computed]
    public function llmPayloadByRequestId(): array
    {
        $ids = collect($this->requests->items())->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        // Берём latest system_close_lost per request_id.
        $rows = RequestStateChange::query()
            ->whereIn('request_id', $ids)
            ->where('event', 'system_close_lost')
            ->orderByDesc('id')
            ->get(['request_id', 'payload']);
        $byId = [];
        foreach ($rows as $row) {
            if (isset($byId[$row->request_id])) {
                continue; // только первая (latest) запись
            }
            $payload = is_array($row->payload) ? $row->payload : [];
            $byId[$row->request_id] = [
                'verdict' => $payload['llm_verdict'] ?? null,
                'confidence' => $payload['llm_confidence'] ?? null,
                'reasoning' => $payload['llm_reasoning'] ?? null,
            ];
        }
        return $byId;
    }

    public function render()
    {
        return view('livewire.admin.auto-closed.index');
    }
}
