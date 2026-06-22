<?php

namespace App\Livewire\Mail;

use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\SharedMailService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * «Почта выбывших» (РОП/директор/секретарь) / «Почта» (менеджер) — переписка из
 * личных ящиков СЕЙЧАС недоступных менеджеров, НЕ привязанная к заявкам.
 *
 *  - РОП/директор/админ: фильтр по выбывшему менеджеру + НАЗНАЧЕНИЕ ответственного.
 *  - Менеджер: по умолчанию фильтр «Назначенные мне»; отвечает на свои назначенные
 *    письма со своего ящика. Назначать НЕ может (только руководители).
 *
 * Открытие письма → read_at; флаг прочитанности можно сбросить. См.
 * App\Services\Mail\SharedMailService.
 */
class AbsentInbox extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'mgr')]
    public ?int $managerId = null; // фильтр по выбывшему менеджеру (mailbox owner)

    #[Url(as: 'f')]
    public string $assignmentFilter = ''; // '' = все | mine | unassigned

    #[Url(as: 'read')]
    public string $readFilter = ''; // '' = все | unread | read

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'expand')]
    public ?int $expandedId = null;

    public ?int $replyingId = null;

    public string $replyBody = '';

    public string $replyCc = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $newFiles = [];

    public function mount(): void
    {
        abort_unless($this->canAccess(), 403);
        // Менеджеру по умолчанию — «Назначенные мне».
        if ($this->assignmentFilter === '' && $this->isManagerRole() && ! $this->canAssign) {
            $this->assignmentFilter = 'mine';
        }
    }

    private function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([
            Role::Manager->value, Role::HeadOfSales->value,
            Role::Director->value, Role::Secretary->value, Role::Admin->value,
        ]) ?? false;
    }

    private function isManagerRole(): bool
    {
        return auth()->user()?->hasRole(Role::Manager->value) ?? false;
    }

    /** Назначать ответственного могут только РОП/директор/админ. */
    #[Computed]
    public function canAssign(): bool
    {
        return auth()->user()?->hasAnyRole([
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]) ?? false;
    }

    /**
     * Директорат и админ могут отвечать на письмо БЕЗ назначения ответственного
     * (ответ уходит с ящика выбывшего — см. SharedMailService::sendReply).
     */
    #[Computed]
    public function canReplyUnassigned(): bool
    {
        return auth()->user()?->hasAnyRole([
            Role::Director->value, Role::Admin->value,
        ]) ?? false;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingManagerId(): void
    {
        $this->resetPage();
    }

    public function updatingAssignmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatingReadFilter(): void
    {
        $this->resetPage();
    }

    /** Выбывшие менеджеры (для фильтра-дропдауна). @return \Illuminate\Support\Collection<int, User> */
    #[Computed]
    public function absentManagers()
    {
        $ids = app(SharedMailService::class)->unavailableManagerIds();

        return $ids === []
            ? collect()
            : User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    /** Доступные менеджеры, кому можно назначить письмо. @return \Illuminate\Support\Collection<int, User> */
    #[Computed]
    public function assignableManagers()
    {
        return User::role(Role::requestHandlerRoles())
            ->available()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $svc = app(SharedMailService::class);
        $authId = (int) auth()->id();

        $q = $svc->baseQuery()
            ->with([
                'mailbox:id,email,owner_user_id',
                'mailbox.owner:id,name',
                'sharedAssignment.assignedUser:id,name',
            ])
            ->withCount('attachments');

        if ($this->managerId) {
            $q->whereHas('mailbox', fn ($m) => $m->where('owner_user_id', $this->managerId));
        }

        if ($this->assignmentFilter === 'mine') {
            $q->whereHas('sharedAssignment', fn ($a) => $a->where('assigned_user_id', $authId));
        } elseif ($this->assignmentFilter === 'unassigned') {
            $q->whereDoesntHave('sharedAssignment', fn ($a) => $a->whereNotNull('assigned_user_id'));
        }

        if ($this->readFilter === 'read') {
            $q->whereHas('sharedAssignment', fn ($a) => $a->whereNotNull('read_at'));
        } elseif ($this->readFilter === 'unread') {
            $q->whereDoesntHave('sharedAssignment', fn ($a) => $a->whereNotNull('read_at'));
        }

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
            $q->where(fn ($w) => $w->where('subject', 'ilike', $like)
                ->orWhere('from_email', 'ilike', $like)
                ->orWhere('from_name', 'ilike', $like));
        }

        return $q->orderByRaw('sent_at DESC NULLS LAST')->orderByDesc('id')->paginate(25);
    }

    /** Счётчик «назначенные мне» (для бейджа/нав). */
    #[Computed]
    public function assignedToMeCount(): int
    {
        return app(SharedMailService::class)->baseQuery()
            ->whereHas('sharedAssignment', fn ($a) => $a->where('assigned_user_id', auth()->id()))
            ->count();
    }

    /** Тред переписки вокруг раскрытого письма (оригинал + продолжения + наши ответы). */
    #[Computed]
    public function thread()
    {
        if (! $this->expandedId) {
            return collect();
        }
        $original = $this->findVisible($this->expandedId);
        if (! $original) {
            return collect();
        }

        return app(SharedMailService::class)->threadFor($original);
    }

    public function toggleExpand(int $id): void
    {
        if ($this->expandedId === $id) {
            $this->expandedId = null;
            $this->cancelReply();
            unset($this->thread);

            return;
        }
        $email = $this->findVisible($id);
        if (! $email) {
            return;
        }
        $this->expandedId = $id;
        $this->cancelReply();
        // Открыл письмо — отмечаем прочитанным.
        app(SharedMailService::class)->markRead($email, auth()->user());
        unset($this->rows);
        unset($this->thread);
    }

    public function assign(int $emailId, ?int $managerId): void
    {
        abort_unless($this->canAssign, 403);
        $email = $this->findVisible($emailId);
        if (! $email) {
            return;
        }
        $mid = ($managerId !== null && $managerId > 0) ? $managerId : null;
        app(SharedMailService::class)->assign($email, $mid, auth()->user());
        unset($this->rows);
        $name = $mid ? (User::find($mid)?->name ?? '') : null;
        $this->dispatch('toast', message: $mid ? "Назначено: {$name}" : 'Назначение снято.', type: 'success');
    }

    public function markUnread(int $emailId): void
    {
        $email = $this->findVisible($emailId);
        if (! $email) {
            return;
        }
        app(SharedMailService::class)->markUnread($email);
        unset($this->rows);
    }

    public function startReply(int $emailId): void
    {
        $email = $this->findVisible($emailId);
        if (! $email || ! $this->canReplyTo($email)) {
            $this->dispatch('toast', message: 'Отвечать может только назначенный менеджер.', type: 'error');

            return;
        }
        $this->replyingId = $emailId;
        $this->replyBody = '';
        $this->replyCc = '';
        $this->newFiles = [];
        $this->resetErrorBag('newFiles.*');
    }

    public function cancelReply(): void
    {
        $this->replyingId = null;
        $this->replyBody = '';
        $this->replyCc = '';
        $this->newFiles = [];
        $this->resetErrorBag('newFiles.*');
    }

    /** Убрать один из приложенных, но ещё не отправленных файлов. */
    public function removeNewFile(int $index): void
    {
        unset($this->newFiles[$index]);
        $this->newFiles = array_values($this->newFiles);
    }

    public function sendReply(): void
    {
        $email = $this->replyingId ? $this->findVisible($this->replyingId) : null;
        if (! $email || ! $this->canReplyTo($email)) {
            $this->dispatch('toast', message: 'Отвечать может только назначенный менеджер.', type: 'error');

            return;
        }

        if (! empty($this->newFiles)) {
            $this->validate(['newFiles.*' => 'file|max:25600']); // 25 МБ на файл
        }

        // SMTP-отправка с вложениями к Yandex может занимать 20-40+ сек.
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $result = app(SharedMailService::class)->sendReply(
            $email,
            auth()->user(),
            $this->replyBody,
            $this->newFiles,
            $this->parseRecipients($this->replyCc),
        );
        if (! ($result['success'] ?? false)) {
            $this->dispatch('toast', message: 'Не отправлено: '.($result['error'] ?? ''), type: 'error');

            return;
        }

        $this->cancelReply();
        unset($this->rows);
        unset($this->thread);
        $this->dispatch('toast', message: 'Ответ отправлен с вашего ящика.', type: 'success');
    }

    /**
     * «Имя <email>, foo@bar» → [['email'=>…, 'name'=>…], …] (только валидные).
     *
     * @return array<int, array{email: string, name: string}>
     */
    private function parseRecipients(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $items = preg_split('/[,;\n]+/u', $raw) ?: [];
        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^(.*?)<([^>]+)>$/u', $item, $m)) {
                $email = trim($m[2]);
                $name = trim($m[1], " \t\"");
            } else {
                $email = $item;
                $name = '';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['email' => $email, 'name' => $name];
            }
        }

        return $out;
    }

    /**
     * Может ли текущий пользователь отвечать на письмо: назначенный менеджер
     * ИЛИ директорат/админ (без назначения — ответ с ящика выбывшего).
     */
    private function canReplyTo(EmailMessage $email): bool
    {
        if (auth()->user() === null) {
            return false;
        }
        if ($this->canReplyUnassigned) {
            return true;
        }
        $assignedTo = $email->sharedAssignment?->assigned_user_id
            ?? $email->loadMissing('sharedAssignment')->sharedAssignment?->assigned_user_id;

        return $assignedTo !== null && (int) $assignedTo === (int) auth()->id();
    }

    /** Письмо в пределах ленты (защита: только из ящиков выбывших, не-заявочное). */
    private function findVisible(int $id): ?EmailMessage
    {
        return app(SharedMailService::class)->baseQuery()
            ->with('sharedAssignment')
            ->whereKey($id)
            ->first();
    }

    public function render()
    {
        return view('livewire.mail.absent-inbox');
    }
}
