<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Invoices\PaymentImportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Раздел «Счета» (/dashboard/invoices) — Phase 4 step 3.
 *
 * Кто видит:
 *  - manager — только свои Invoice (через request.assigned_user_id).
 *  - head_of_sales / director / secretary / admin — все.
 *
 * Actions:
 *  - markPaid(id)  — invoice.status=paid + Request → Paid
 *  - cancel(id, reason) — invoice.status=cancelled + Request → AwaitingInvoice
 *
 * Фильтры: статус, период, поиск по номеру, менеджер (для privileged).
 */
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'pending';

    #[Url(as: 'period')]
    public string $period = '30d';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'mgr')]
    public ?int $managerId = null;

    #[Url(as: 'scope')]
    public string $scope = 'mine';   // mine | all (для privileged)

    /** Confirm-state для cancel: id invoice, reason. */
    public ?int $cancellingInvoiceId = null;
    public string $cancellationReason = '';

    /* --- Массовая оплата по списку номеров --- */
    public bool $bulkOpen = false;
    public string $bulkNumbers = '';
    public bool $bulkPreviewed = false;
    /** @var array<int, array<string, mixed>> найденные счета для превью */
    public array $bulkFound = [];
    /** @var array<int, string> номера из списка без совпадений */
    public array $bulkNotFound = [];
    /** @var array<int, int> id выбранных к оплате счетов */
    public array $bulkSelectedIds = [];

    /* --- Импорт оплат из 1С (xlsx) --- */
    public bool $importOpen = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $importFile = null;

    public bool $importPreviewed = false;

    /** Ключ кэша с классифицированными строками (между превью и применением). */
    public ?string $importToken = null;

    public ?string $importFileName = null;

    /** @var array<string, array{count:int, sum:float}> итоги классификации */
    public array $importSummary = [];

    /** @var array<int, array<string, mixed>> строки для превью-таблицы (обрезаны) */
    public array $importPreviewRows = [];

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        // По умолчанию privileged видят all, менеджеры — mine.
        if ($this->canSeeAll()) {
            // если не задан явно в URL — все
            if (! request()->has('scope')) {
                $this->scope = 'all';
            }
        } else {
            $this->scope = 'mine'; // менеджеру не разрешаем «all» через URL
        }
    }

    /* ------------------------------- Filters -------------------------------- */

    public function setStatus(string $s): void
    {
        $allowed = ['all', 'pending', 'paid', 'partially_paid', 'expired', 'cancelled', 'overdue'];
        $this->statusFilter = in_array($s, $allowed, true) ? $s : 'pending';
        $this->resetPage();
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['today', '7d', '30d', '90d', 'all'], true) ? $p : '30d';
        $this->resetPage();
    }

    public function setScope(string $s): void
    {
        if (! $this->canSeeAll()) {
            return; // менеджеры не могут переключать
        }
        $this->scope = $s === 'all' ? 'all' : 'mine';
        $this->resetPage();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingManagerId(): void { $this->resetPage(); }

    /* ----------------------------- Permissions ------------------------------ */

    public function canSeeAll(): bool
    {
        $u = auth()->user();
        return $u?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
            Role::Admin->value,
        ]) ?? false;
    }

    private function canAct(Invoice $invoice): bool
    {
        $u = auth()->user();
        if (! $u) {
            return false;
        }
        if ($this->canSeeAll()) {
            return true;
        }
        // менеджер — владелец заявки ИЛИ acting через активную делегацию.
        $req = $invoice->request;

        return $req !== null && $req->isAccessibleBy($u);
    }

    /* -------------------------------- Actions ------------------------------- */

    public function markPaid(int $invoiceId, InvoiceService $service): void
    {
        $invoice = Invoice::with('request')->findOrFail($invoiceId);
        if (! $this->canAct($invoice)) {
            $this->dispatch('toast', message: 'Нет прав на оплату счёта.', type: 'error');
            return;
        }
        try {
            $service->markPaid($invoice, auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ошибка: ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} помечен оплаченным.", type: 'success');
    }

    public function startCancel(int $invoiceId): void
    {
        $this->cancellingInvoiceId = $invoiceId;
        $this->cancellationReason = '';
    }

    public function cancelStartCancel(): void
    {
        $this->cancellingInvoiceId = null;
        $this->cancellationReason = '';
    }

    public function confirmCancel(InvoiceService $service): void
    {
        if (! $this->cancellingInvoiceId) {
            return;
        }
        $invoice = Invoice::with('request')->findOrFail($this->cancellingInvoiceId);
        if (! $this->canAct($invoice)) {
            $this->dispatch('toast', message: 'Нет прав на аннулирование счёта.', type: 'error');
            $this->cancellingInvoiceId = null;
            return;
        }
        $reason = trim($this->cancellationReason);
        if ($reason === '') {
            $this->dispatch('toast', message: 'Укажите причину аннулирования.', type: 'error');
            return;
        }
        try {
            $service->cancel($invoice, $reason, auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ошибка: ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} аннулирован.", type: 'success');
        $this->cancellingInvoiceId = null;
        $this->cancellationReason = '';
    }

    /* --------------------------- Массовая оплата ---------------------------- */

    public function openBulk(): void
    {
        $this->resetBulk();
        $this->bulkOpen = true;
    }

    public function closeBulk(): void
    {
        $this->bulkOpen = false;
        $this->resetBulk();
    }

    public function backToBulkInput(): void
    {
        $this->bulkPreviewed = false;
        $this->bulkFound = [];
        $this->bulkNotFound = [];
        $this->bulkSelectedIds = [];
    }

    private function resetBulk(): void
    {
        $this->bulkNumbers = '';
        $this->bulkPreviewed = false;
        $this->bulkFound = [];
        $this->bulkNotFound = [];
        $this->bulkSelectedIds = [];
    }

    /**
     * Шаг 1→2: распарсить номера, найти счета, категоризировать и предвыбрать
     * подходящие к оплате (Pending/Expired + есть права).
     */
    public function previewBulk(): void
    {
        // Парсинг: переносы строк / запятые / точки с запятой; trim; dedupe по lower-case.
        $raw = preg_split('/[\r\n,;]+/u', $this->bulkNumbers) ?: [];
        $numbers = [];
        foreach ($raw as $n) {
            $n = trim($n);
            if ($n !== '') {
                $numbers[mb_strtolower($n)] = $n; // ключ — lower, значение — оригинал
            }
        }
        if ($numbers === []) {
            $this->dispatch('toast', message: 'Вставьте хотя бы один номер счёта.', type: 'error');
            return;
        }

        $query = Invoice::query()
            ->with(['request:id,internal_code,assigned_user_id', 'request.assignedUser:id,name'])
            ->whereIn(DB::raw('lower(invoice_number)'), array_keys($numbers));

        // Менеджер находит только счета своих заявок (как scope «mine») — чужие
        // не показываем (попадут в «не найдено», без утечки существования).
        if (! $this->canSeeAll()) {
            $uid = auth()->id();
            $query->whereHas('request', fn ($r) => $r->where('assigned_user_id', $uid)
                ->orWhereHas('activeDelegations', fn ($d) => $d->where('acting_user_id', $uid)));
        }

        $invoices = $query->orderBy('invoice_number')->orderByDesc('id')->get();

        $found = [];
        $selected = [];
        $matchedLower = [];
        foreach ($invoices as $inv) {
            $matchedLower[mb_strtolower((string) $inv->invoice_number)] = true;
            $status = $inv->status;
            $canAct = $this->canAct($inv);
            $payable = in_array($status, InvoiceService::PAYABLE_STATUSES, true);
            $eligible = $canAct && $payable;
            $reason = '';
            if (! $canAct) {
                $reason = 'нет прав';
            } elseif (! $payable) {
                $reason = match ($status) {
                    InvoiceStatus::Paid => 'уже оплачен',
                    InvoiceStatus::Cancelled => 'аннулирован',
                    default => 'недоступен',
                };
            }

            $found[] = [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'request_code' => $inv->request?->internal_code,
                'status' => $status->value,
                'status_label' => $status->label(),
                'manager' => $inv->request?->assignedUser?->name,
                'amount' => $inv->amount_snapshot !== null ? (string) $inv->amount_snapshot : null,
                'eligible' => $eligible,
                'reason' => $reason,
            ];
            if ($eligible) {
                $selected[] = $inv->id;
            }
        }

        $notFound = [];
        foreach ($numbers as $lower => $original) {
            if (! isset($matchedLower[$lower])) {
                $notFound[] = $original;
            }
        }

        $this->bulkFound = $found;
        $this->bulkNotFound = $notFound;
        $this->bulkSelectedIds = $selected;
        $this->bulkPreviewed = true;
    }

    /**
     * Шаг 2: пометить выбранные счета оплаченными. Повторно проверяем права и
     * статус (защита от гонки и подмены id), затем bulkMarkPaid.
     */
    public function confirmBulk(InvoiceService $service): void
    {
        if ($this->bulkSelectedIds === []) {
            $this->dispatch('toast', message: 'Не выбрано ни одного счёта.', type: 'error');
            return;
        }

        $invoices = Invoice::with('request')
            ->whereKey($this->bulkSelectedIds)
            ->get()
            ->filter(fn (Invoice $inv) => $this->canAct($inv)
                && in_array($inv->status, InvoiceService::PAYABLE_STATUSES, true));

        if ($invoices->isEmpty()) {
            $this->dispatch('toast', message: 'Нет счетов, доступных к оплате.', type: 'error');
            return;
        }

        $result = $service->bulkMarkPaid($invoices, auth()->user());

        $failedCount = count($result['failed']);
        $msg = "Оплачено счетов: {$result['paid']}";
        if ($failedCount > 0) {
            $msg .= " · с ошибкой: {$failedCount}";
        }
        $this->dispatch('toast', message: $msg, type: $result['paid'] > 0 ? 'success' : 'error');

        $this->bulkOpen = false;
        $this->resetBulk();
    }

    /* ------------------------- Импорт оплат из 1С --------------------------- */

    public function openImport(): void
    {
        if (! $this->canSeeAll()) {
            return;
        }
        $this->resetImport();
        $this->importOpen = true;
    }

    public function closeImport(): void
    {
        $this->importOpen = false;
        $this->resetImport();
    }

    public function backToImportUpload(): void
    {
        $this->importPreviewed = false;
        $this->importFile = null;
        $this->importToken = null;
        $this->importSummary = [];
        $this->importPreviewRows = [];
    }

    private function resetImport(): void
    {
        $this->importFile = null;
        $this->importPreviewed = false;
        $this->importToken = null;
        $this->importFileName = null;
        $this->importSummary = [];
        $this->importPreviewRows = [];
    }

    /**
     * Шаг 1→2: распарсить xlsx, классифицировать против БД, показать превью.
     * Классифицированные строки — в кэш (state Livewire не тянет сотни строк).
     */
    public function previewImport(PaymentImportService $service): void
    {
        abort_unless($this->canSeeAll(), 403);
        $this->validate(
            ['importFile' => 'required|file|mimes:xlsx,xls|max:10240'],
            [],
            ['importFile' => 'файл выгрузки'],
        );

        $parsed = $service->parse($this->importFile->getRealPath());
        if ($parsed['error'] !== null) {
            $this->dispatch('toast', message: $parsed['error'], type: 'error');

            return;
        }
        if ($parsed['rows'] === []) {
            $this->dispatch('toast', message: 'В файле не найдено строк с оплатами.', type: 'error');

            return;
        }

        $classified = $service->classify($parsed['rows']);

        $this->importToken = (string) Str::uuid();
        Cache::put('payment-import:'.$this->importToken, $classified['rows'], now()->addHours(2));
        $this->importFileName = $this->importFile->getClientOriginalName();
        $this->importSummary = $classified['summary'];

        // Превью: сначала строки, требующие действий, потом остальное; режем до 250.
        $order = ['mark_paid' => 1, 'mark_partial' => 2, 'unknown' => 3, 'already_recorded' => 4, 'skipped_old' => 5, 'already_paid' => 6];
        $rows = $classified['rows'];
        usort($rows, fn ($a, $b) => ($order[$a['action']] ?? 9) <=> ($order[$b['action']] ?? 9));
        $this->importPreviewRows = array_map(fn ($r) => [
            'number' => $r['number_raw'],
            'client' => mb_substr((string) $r['client'], 0, 45),
            'paid_date' => $r['paid_date'],
            'paid_sum' => $r['paid_sum'],
            'percent' => $r['percent'],
            'action' => $r['action'],
            'request_code' => $r['request_code'] ?? null,
            'crm_status' => $r['crm_status'] ?? null,
            'sum_mismatch' => $r['sum_mismatch'] ?? false,
        ], array_slice($rows, 0, 250));
        $this->importPreviewed = true;
    }

    /** Шаг 2: применить импорт (отметить оплаты + журнал). */
    public function applyImport(PaymentImportService $service): void
    {
        abort_unless($this->canSeeAll(), 403);
        if ($this->importToken === null) {
            return;
        }
        $rows = Cache::pull('payment-import:'.$this->importToken);
        if ($rows === null) {
            $this->dispatch('toast', message: 'Превью устарело — загрузите файл заново.', type: 'error');
            $this->backToImportUpload();

            return;
        }

        $import = $service->apply($rows, (string) $this->importFileName, auth()->user());

        $msg = "Импорт применён: оплачено {$import->marked_paid}, частично {$import->marked_partial}, "
            ."внешних оплат {$import->unknown_recorded}, уже были оплачены {$import->already_paid}"
            .($import->errors > 0 ? ", ошибок {$import->errors}" : '').'.';
        $this->dispatch('toast', message: $msg, type: $import->errors > 0 ? 'warning' : 'success');
        session()->flash('invoices_status', $msg);

        $this->importOpen = false;
        $this->resetImport();
        unset($this->invoices);
    }

    /** Человекочитаемые метки классификации для превью. */
    public function importActionLabel(string $action): array
    {
        return match ($action) {
            'mark_paid' => ['✓ будет оплачен', 'chip-ok'],
            'mark_partial' => ['◐ частичная оплата', 'chip-sky'],
            'unknown' => ['? нет в системе → внешняя', 'chip-warn'],
            'already_recorded' => ['внешняя уже записана', 'chip-neutral'],
            'skipped_old' => ['до запуска — пропуск', 'chip-neutral'],
            'already_paid' => ['уже оплачен', 'chip-neutral'],
            default => [$action, 'chip-neutral'],
        };
    }

    /* ------------------------------ Computed -------------------------------- */

    #[Computed]
    public function invoices()
    {
        return $this->buildQuery()
            ->with([
                'request:id,internal_code,assigned_user_id,status,client_name,client_email',
                'request.assignedUser:id,name',
                'createdByUser:id,name',
                'paidByUser:id,name',
            ])
            ->orderByRaw('
                CASE status
                    WHEN \'pending\' THEN 1
                    WHEN \'expired\' THEN 2
                    WHEN \'partially_paid\' THEN 3
                    WHEN \'paid\' THEN 4
                    WHEN \'cancelled\' THEN 5
                    ELSE 6
                END
            ')
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function availableManagers(): Collection
    {
        return User::query()
            ->active()
            ->role(Role::requestHandlerRoles())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function buildQuery(): Builder
    {
        $q = Invoice::query();

        // Scope: mine vs all (для privileged). «Mine» = свои заявки + те, что
        // делегированы мне на время отсутствия владельца (active delegation) —
        // согласовано с пулом заявок.
        $u = auth()->user();
        if ($this->scope === 'mine' || ! $this->canSeeAll()) {
            $q->whereHas('request', fn ($r) => $r->where('assigned_user_id', $u->id)
                ->orWhereHas('activeDelegations', fn ($d) => $d->where('acting_user_id', $u->id)));
        } elseif ($this->managerId) {
            $q->whereHas('request', fn ($r) => $r->where('assigned_user_id', $this->managerId));
        }

        // Статус.
        if ($this->statusFilter === 'overdue') {
            // overdue = pending + expires_at < now
            $q->where('status', InvoiceStatus::Pending->value)
              ->where('expires_at', '<', now());
        } elseif ($this->statusFilter !== 'all'
            && in_array($this->statusFilter, InvoiceStatus::values(), true)) {
            $q->where('status', $this->statusFilter);
        }

        // Период (по issued_at).
        $cutoff = match ($this->period) {
            'today' => now()->startOfDay(),
            '7d'    => now()->subDays(7),
            '30d'   => now()->subDays(30),
            '90d'   => now()->subDays(90),
            default => null,
        };
        if ($cutoff !== null) {
            $q->where('issued_at', '>=', $cutoff);
        }

        // Search по invoice_number.
        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $q->where('invoice_number', 'ilike', $needle);
        }

        return $q;
    }

    public function statusChipClass(string $status): string
    {
        $enum = InvoiceStatus::tryFrom($status);
        return $enum?->chipClass() ?? 'chip-neutral';
    }

    public function statusLabel(string $status): string
    {
        return InvoiceStatus::tryFrom($status)?->label() ?? $status;
    }

    public function render()
    {
        return view('livewire.invoices.index');
    }
}
