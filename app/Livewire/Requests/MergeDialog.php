<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\Request\RequestMergeService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal-диалог для слияния заявки-дубликата (loser) в текущую (winner).
 *
 * Открывается из action-panel кнопкой «⊌ Слить дубликат» — слушает
 * `open-merge-dialog`. Mounted в Detail (winner = $this->request).
 *
 * Кандидаты:
 *  - Все active Request с тем же client_email (case-i), исключая winner.
 *  - ЕСЛИ у winner есть external-маркеры (LZ-REQ-NNNN) в любом из её писем:
 *    кандидаты сужаются до тех, у кого есть хотя бы один общий маркер.
 *    Это резко уменьшает шум для системных партнёров (Liftway-saas), где
 *    один client_email обслуживает десятки независимых LZ-REQ-заявок.
 *  - Если у winner нет внешних маркеров — фильтр не применяется, показываем
 *    всех active кандидатов клиента (обычный кейс).
 *
 * Выбор кандидата → preview статистики (items_to_add/skip, emails, batches).
 * Кнопка «Слить» → RequestMergeService::merge → flash + reload.
 */
class MergeDialog extends Component
{
    public int $requestId;
    public bool $open = false;
    public ?int $selectedLoserId = null;
    public string $search = '';

    /**
     * Режим «другой e-mail отправителя» — тот же реальный клиент пишет с
     * другого ящика (кейс M-2026-8787 → M-2026-8762). Включается ОТДЕЛЬНОЙ
     * кнопкой и только привилегированными (см. canCrossClient). По умолчанию
     * выключен — обычный список кандидатов не меняется.
     */
    public bool $crossClient = false;

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
        $this->crossClient = false;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->selectedLoserId = null;
        $this->crossClient = false;
    }

    public function selectLoser(int $id): void
    {
        $this->selectedLoserId = $id;
    }

    /**
     * Кросс-почтовое слияние доступно только РОП/директору/админу — риск
     * склеить двух разных заказчиков слишком высок для рядового менеджера
     * (решение заказчика 2026-07-17). Дублируется в RequestMergeService::validate.
     */
    #[Computed]
    public function canCrossClient(): bool
    {
        $u = auth()->user();

        return $u !== null && $u->hasAnyRole([
            \App\Enums\Role::HeadOfSales->value,
            \App\Enums\Role::Director->value,
            \App\Enums\Role::Admin->value,
        ]);
    }

    /** E-mail клиента текущей заявки — для предупреждения о расхождении. */
    #[Computed]
    public function winnerEmail(): string
    {
        return (string) ($this->winner()?->client_email ?? '');
    }

    public function toggleCrossClient(): void
    {
        if (! $this->canCrossClient) {
            return;
        }
        $this->crossClient = ! $this->crossClient;
        $this->selectedLoserId = null;
        $this->search = '';
        $this->resetErrorBag();
        unset($this->candidates);
    }

    /**
     * External-маркеры из писем winner-а (LZ-REQ-NNNN и др.).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function winnerCodes(): array
    {
        $winner = $this->winner();
        if ($winner === null) {
            return [];
        }
        $patterns = (array) config('services.mail.external_codes', []);
        if (empty($patterns)) {
            return [];
        }

        $codes = [];
        EmailMessage::query()
            ->where('related_request_id', $winner->id)
            ->orderBy('id')
            ->chunkById(50, function ($messages) use (&$codes, $patterns) {
                foreach ($messages as $m) {
                    $h = (string) $m->subject . "\n" . (string) $m->body_plain;
                    foreach ($patterns as $p) {
                        if (preg_match_all($p, $h, $mm)) {
                            foreach ($mm[0] as $c) {
                                $codes[$c] = true;
                            }
                        }
                    }
                }
            });

        return array_keys($codes);
    }

    /**
     * Кандидаты на слияние: active Request с тем же client_email
     * И (если у winner есть LZ-REQ-коды) с хотя бы одним общим маркером.
     */
    #[Computed]
    public function candidates()
    {
        $winner = $this->winner();
        if ($winner === null) {
            return collect();
        }

        $activeValues = array_map(fn (RequestStatus $s) => $s->value, self::ACTIVE_STATUSES);

        if ($this->crossClient && $this->canCrossClient) {
            return $this->crossClientCandidates($winner, $activeValues);
        }

        if ($winner->client_email === '') {
            return collect();
        }

        $q = RequestModel::query()
            ->where('id', '!=', $winner->id)
            ->whereRaw('LOWER(client_email) = ?', [mb_strtolower(trim($winner->client_email))])
            ->whereIn('status', $activeValues)
            ->withCount(['items' => fn ($q) => $q->where('is_active', true)])
            ->with([
                'assignedUser:id,name',
                // Топ-5 active позиций — для preview в карточке кандидата.
                'items' => fn ($q) => $q
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->limit(5)
                    ->select(['id', 'request_id', 'position', 'parsed_name', 'parsed_brand', 'parsed_article', 'parsed_qty']),
            ])
            ->orderByDesc('created_at')
            ->limit(50);

        // Если у winner есть LZ-REQ-маркеры — фильтруем кандидатов на тех,
        // у кого в любом из их писем есть хотя бы один общий маркер.
        $codes = $this->winnerCodes;
        if (! empty($codes)) {
            $q->whereExists(function ($sub) use ($codes) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('email_messages')
                    ->whereColumn('email_messages.related_request_id', 'requests.id')
                    ->where(function ($w) use ($codes) {
                        foreach ($codes as $code) {
                            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $code) . '%';
                            $w->orWhere('subject', 'ilike', $needle)
                                ->orWhere('body_plain', 'ilike', $needle);
                        }
                    });
            });
        }

        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('internal_code', 'ilike', $needle)
                    ->orWhere('subject', 'ilike', $needle);
            });
        }

        $candidates = $q->get(['id', 'internal_code', 'subject', 'status', 'assigned_user_id', 'created_at', 'client_email']);

        // Бонус: для каждого кандидата извлечь его external-маркеры — отобразим в UI.
        $patterns = (array) config('services.mail.external_codes', []);
        if (! empty($patterns) && $candidates->isNotEmpty()) {
            $ids = $candidates->pluck('id')->all();
            $msgs = EmailMessage::query()
                ->whereIn('related_request_id', $ids)
                ->get(['related_request_id', 'subject', 'body_plain']);
            $codesByReq = [];
            foreach ($msgs as $m) {
                $h = (string) $m->subject . "\n" . (string) $m->body_plain;
                foreach ($patterns as $p) {
                    if (preg_match_all($p, $h, $mm)) {
                        foreach ($mm[0] as $c) {
                            $codesByReq[$m->related_request_id][$c] = true;
                        }
                    }
                }
            }
            foreach ($candidates as $c) {
                $c->setAttribute('ext_codes', array_keys($codesByReq[$c->id] ?? []));
            }
        }

        return $candidates;
    }

    /**
     * Кандидаты в режиме «другой e-mail отправителя»: ищем по НОМЕРУ заявки /
     * теме / почте среди ВСЕХ заявок (любой client_email) + подмешиваем заявки
     * той же организации, что и winner. Статусы — активные + closed_lost:
     * дубль часто уже закрыт (авто-закрытие или руками), его и надо вложить.
     *
     * Без строки поиска и без организации у winner — пусто: иначе это был бы
     * список «все заявки системы».
     *
     * @param  array<int, string>  $activeValues
     * @return \Illuminate\Support\Collection<int, RequestModel>
     */
    private function crossClientCandidates(RequestModel $winner, array $activeValues)
    {
        $search = trim($this->search);
        $orgId = $winner->organization_id;
        if ($search === '' && $orgId === null) {
            return collect();
        }

        $statuses = array_merge($activeValues, [RequestStatus::ClosedLost->value]);

        return RequestModel::query()
            ->where('id', '!=', $winner->id)
            ->whereIn('status', $statuses)
            ->where(function ($w) use ($search, $orgId) {
                if ($orgId !== null) {
                    $w->orWhere('organization_id', $orgId);
                }
                if ($search !== '') {
                    $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
                    $w->orWhere('internal_code', 'ilike', $needle)
                        ->orWhere('subject', 'ilike', $needle)
                        ->orWhere('client_email', 'ilike', $needle);
                }
            })
            ->withCount(['items' => fn ($q) => $q->where('is_active', true)])
            ->with([
                'assignedUser:id,name',
                'items' => fn ($q) => $q
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->limit(5)
                    ->select(['id', 'request_id', 'position', 'parsed_name', 'parsed_brand', 'parsed_article', 'parsed_qty']),
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'internal_code', 'subject', 'status', 'assigned_user_id', 'created_at', 'client_email', 'organization_id']);
    }

    /**
     * @return array{items_to_add: int, items_to_skip: int, emails_to_move: int, batches_to_move: int, conflicts: array<int, string>, cross_client: bool}|null
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

        return app(RequestMergeService::class)->preview($winner, $loser, $this->crossClient && $this->canCrossClient);
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
            $stats = $service->merge($winner, $loser, auth()->user(), $this->crossClient && $this->canCrossClient);
        } catch (\Throwable $e) {
            $this->addError('selectedLoserId', $e->getMessage());

            return;
        }

        session()->flash('status', sprintf(
            'Заявка %s объединена с этой. Перенесено: позиций +%d (пропущено %d), писем %d, уточнений %d.',
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
