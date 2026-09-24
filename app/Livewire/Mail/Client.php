<?php

namespace App\Livewire\Mail;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Enums\MailFolder;
use App\Enums\Role;
use App\Jobs\Mail\SyncMailboxFolderJob;
use App\Livewire\Concerns\RendersEmailBody;
use App\Models\AutoQuoteSnapshot;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Models\MailLabel;
use App\Models\Request;
use App\Models\SupplierInquiry;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\EmailToRequestPromoter;
use App\Services\Mail\ImapFolderSyncService;
use App\Services\Mail\ImapSeenSyncService;
use App\Services\Mail\MailboxAccessService;
use App\Services\Mail\MailboxFolderService;
use App\Services\Mail\MailReadService;
use App\Services\Mail\MailReassignArchiverService;
use App\Services\Mail\MessageLabelService;
use App\Services\Mail\SharedMailService;
use App\Services\Mail\SupplierCcInboxService;
use App\Services\Quotes\AutoQuoteOfferService;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Раздел «Почта» — почтовый клиент менеджера (Фаза 1: чтение).
 *
 * 3 панели: ящики+папки | список тредов | чтение. Область видимости — все
 * доступные ящики (личный + общие + делегированные, см. MailboxAccessService).
 * «Прочитано»/флаг — персональные (MailReadService), IMAP \Seen НЕ трогаем.
 *
 * Отдельный инструмент, НЕ /dashboard/mail (та — org-wide витрина для
 * руководителей). Доступ: manager + admin.
 *
 * Фаза 2 добавит ответ/пересылку/написать (богатый редактор) + гибрид-хук.
 */
class Client extends Component
{
    use RendersEmailBody;

    #[Url(as: 'mbox')]
    public ?int $selectedMailboxId = null;

    #[Url(as: 'folder')]
    public string $folder = 'inbox';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'open')]
    public ?int $openId = null;

    /**
     * Фильтр «письма заявки»: /dashboard/mail/inbox?request=<id> — все письма
     * заявки (входящие и исходящие) по всем доступным ящикам, без папок.
     * Ссылка из карточки заявки. Сбрасывается выбором ящика/папки/поиском.
     */
    #[Url(as: 'request')]
    public ?int $requestId = null;

    /** Фильтр по метке (id из mail_labels); null — без фильтра. */
    #[Url(as: 'label')]
    public ?int $labelId = null;

    /**
     * Показывать только непрочитанные. Непрочитанное — входящее, у которого
     * нет отметки о прочтении ИМЕННО у этого человека: в общем ящике письмо,
     * прочитанное коллегой, для остальных остаётся новым.
     */
    #[Url(as: 'unread')]
    public bool $unreadOnly = false;

    public function toggleUnreadOnly(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;
        $this->notice = null;
        $this->resetView();
    }

    /** Когда в этой сессии последний раз жали «синхронизировать». */
    public ?string $syncedAt = null;

    /**
     * Короткое сообщение под шапкой списка (метки, синхронизация). Не toast:
     * событие 'toast' в проекте никто не слушает — рендерера нет.
     */
    public ?string $notice = null;

    /**
     * Где искать при непустом поиске: 'all' — по всем папкам ящика (по умолчанию),
     * 'inbox' — только не разложенное по папкам, 'f:<id>' — конкретная папка.
     * Вне поиска не действует.
     */
    #[Url(as: 'in')]
    public string $searchIn = 'all';

    /**
     * Порядок писем в открытой переписке: 'asc' — старые сверху, 'desc' — новые.
     * Та же персональная настройка, что во вкладке «Переписка» карточки заявки
     * (users.thread_sort_order) — переключение в одном месте действует везде.
     */
    public string $threadSort = 'asc';

    public function toggleThreadSort(): void
    {
        $this->threadSort = $this->threadSort === 'asc' ? 'desc' : 'asc';
        $this->user()?->forceFill(['thread_sort_order' => $this->threadSort])->save();
    }

    /**
     * Панель массовых действий закреплена: видна всегда, без выделения —
     * неактивна. По умолчанию выключено (панель всплывает при выделении).
     * Личная настройка, живёт у пользователя.
     */
    public bool $bulkBarPinned = false;

    public function toggleBulkBarPin(): void
    {
        $this->bulkBarPinned = ! $this->bulkBarPinned;
        $this->user()?->forceFill(['mail_bulkbar_pinned' => $this->bulkBarPinned])->save();
    }

    public int $perPage = 40;

    private const PER_PAGE_STEP = 20;

    /** Пауза между ручными синхронизациями одного ящика, секунд. */
    private const SYNC_THROTTLE_SECONDS = 20;

    public function mount(): void
    {
        abort_unless($this->canAccess(), 403, 'Раздел «Почта» доступен менеджерам и админам.');

        $svc = app(MailboxAccessService::class);
        $user = $this->user();
        $ids = $svc->mailboxIdsFor($user);

        // Выбранный ящик должен быть доступен; иначе — по умолчанию.
        if ($this->selectedMailboxId === null || ! in_array($this->selectedMailboxId, $ids, true)) {
            $this->selectedMailboxId = $svc->defaultMailboxId($user);
        }

        $this->threadSort = in_array($user?->thread_sort_order, ['asc', 'desc'], true)
            ? $user->thread_sort_order
            : 'asc';
        $this->bulkBarPinned = (bool) ($user?->mail_bulkbar_pinned ?? false);

        // ?label=<id> из чужой ссылки: метки личные, чужую не применяем.
        if ($this->labelId !== null && $this->myLabel($this->labelId) === null) {
            $this->labelId = null;
        }
    }

    private function canAccess(): bool
    {
        // РОП (head_of_sales) тоже работает в клиенте: у него свой личный ящик
        // + обзор ящиков менеджеров (2026-09-08: Курзаев видел старую витрину).
        return $this->user()?->hasAnyRole([Role::Manager->value, Role::HeadOfSales->value, Role::Admin->value, Role::Director->value]) ?? false;
    }

    private function user(): ?User
    {
        return auth()->user();
    }

    /* ----------------------------- Actions ----------------------------- */

    public function selectMailbox(int $mailboxId): void
    {
        if (! app(MailboxAccessService::class)->canAccessMailbox($this->user(), $mailboxId)) {
            return;
        }
        $this->selectedMailboxId = $mailboxId;
        $this->folder = MailFolder::Inbox->value;
        $this->requestId = null;
        $this->searchIn = 'all';
        unset($this->customFolders);
        $this->resetView();
    }

    public function updatedSearchIn(): void
    {
        $this->perPage = 40;
        $this->openId = null;
        unset($this->threads);
    }

    /** Ищем сейчас? (непустая строка поиска, не режим «письма заявки»). */
    private function isSearching(): bool
    {
        return trim($this->search) !== '' && ! $this->requestId;
    }

    public function selectFolder(string $folder): void
    {
        // `f:<id>` — пользовательская папка выбранного ящика; иначе системная.
        $customId = MailboxFolder::idFromKey($folder);
        if ($customId !== null) {
            $custom = $this->accessibleFolder($customId);
            $this->folder = ($custom && (int) $custom->mailbox_id === (int) $this->selectedMailboxId)
                ? 'f:'.$customId
                : MailFolder::Inbox->value;
        } else {
            $this->folder = MailFolder::tryFromOrDefault($folder)->value;
        }
        $this->requestId = null;
        $this->searchIn = 'all';
        $this->resetView();
    }

    public function updatedSearch(): void
    {
        $this->perPage = 40;
        $this->openId = null;
        if (trim($this->search) !== '') {
            $this->requestId = null;
        }
    }

    /**
     * Клик по чипу заявки в списке: оставить только письма этой заявки.
     *
     * Срезы, которые сейчас стоят поверх папки (метка, «только непрочитанные»,
     * поиск), снимаем: человек просит переписку целиком, а не её остаток после
     * фильтров — иначе половина писем заявки молча не покажется.
     */
    public function filterByRequest(int $requestId): void
    {
        if ($requestId <= 0) {
            return;
        }

        $this->requestId = $requestId;
        $this->search = '';
        $this->labelId = null;
        $this->unreadOnly = false;
        $this->notice = null;
        $this->resetView();
    }

    /** Снять фильтр «письма заявки» (крестик в шапке списка). */
    public function clearRequestFilter(): void
    {
        $this->requestId = null;
        $this->resetView();
    }

    /** Заявка, по которой отфильтрован список (null — обычный режим). */
    #[Computed]
    public function filterRequest(): ?Request
    {
        if (! $this->requestId) {
            return null;
        }

        return Request::query()->find($this->requestId, ['id', 'internal_code', 'status', 'subject', 'onec_number']);
    }

    /**
     * Заявка, по которой идёт переписка, когда само письмо к ней не привязано.
     *
     * Ответ поставщика приходит в личный ящик менеджера и заявкой не становится
     * — и не должен. Но идёт он ПО заявке: её номер стоит в теме ([M-…]) либо
     * зашит в токен запроса ([RFQ-…]), которым мы же эту тему и пометили.
     * В таком письме предлагать «Это заявка!» неверно — заявка уже есть, нужна
     * ссылка на неё.
     *
     * @return array{request: Request, why: string}|null
     */
    #[Computed]
    public function hintedRequest(): ?array
    {
        $anchor = $this->openAnchor;
        if ($anchor === null || $anchor->related_request_id) {
            return null;
        }

        // 1. Соседнее письмо той же переписки уже привязано — самый прямой ответ.
        $sibling = $this->openThread->first(fn (EmailMessage $m) => (bool) $m->related_request_id);
        if ($sibling?->relatedRequest !== null) {
            return ['request' => $sibling->relatedRequest, 'why' => 'по соседнему письму переписки'];
        }

        // 2. Токен запроса поставщику: уникален на пару «заявка × поставщик».
        $inquiries = app(SupplierInquiryService::class);
        $token = $inquiries->extractRfqToken($anchor->subject);
        if ($token !== null) {
            $inquiry = SupplierInquiry::query()
                ->where('rfq_token', $token)
                ->whereNotNull('related_request_id')
                ->with('relatedRequest:id,internal_code,status,onec_number')
                ->first();
            if ($inquiry?->relatedRequest !== null) {
                return ['request' => $inquiry->relatedRequest, 'why' => 'по токену запроса поставщику'];
            }
        }

        // 3. Номер заявки в теме — так его пишут и люди, и чужие тикет-системы.
        if (preg_match('/\bM-\d{4}-\d{1,6}\b/iu', (string) $anchor->subject, $m) === 1) {
            $request = Request::query()
                ->whereRaw('upper(internal_code) = ?', [mb_strtoupper($m[0])])
                ->first(['id', 'internal_code', 'status', 'onec_number']);
            if ($request !== null) {
                return ['request' => $request, 'why' => 'по номеру в теме письма'];
            }
        }

        return null;
    }

    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE_STEP;
    }

    private function resetView(): void
    {
        $this->perPage = 40;
        $this->openId = null;
        unset($this->threads, $this->openThread, $this->openAnchor);
    }

    /**
     * Открыть тред письма → пометить все письма треда прочитанными.
     */
    public function openMessage(int $id): void
    {
        $anchor = $this->findAccessible($id);
        if (! $anchor) {
            return;
        }
        // Черновик — открываем в композере, а не в панели чтения.
        if ($anchor->is_draft) {
            $this->dispatch('mail-open-draft', draftId: $id)->to(Composer::class);

            return;
        }
        $this->openId = $id;
        unset($this->openThread, $this->openAnchor);

        // Прочитанным помечаем ТОЛЬКО открытое письмо, как в обычном почтовике.
        // Раньше открытие гасило всю переписку — при показе одного письма это
        // прятало бы непрочитанные соседние письма из счётчиков.
        // От лица владельца состояния (для личного ящика — его хозяина):
        // список показывает именно его прочитанность. \Seen внутри пишется
        // только когда состояние наше собственное.
        $this->applyReadState([$anchor->id], true);

        // Обновить список (снять «непрочитано») и счётчики.
        unset($this->threads, $this->folders, $this->mailboxes);
    }

    /* ----------------------- Массовые действия ------------------------ */

    /**
     * Чья прочитанность (и флаг) стоит за письмом: у ЛИЧНОГО ящика — его
     * владелец, у общих — текущий пользователь. Ровно то же правило, по
     * которому список показывает «непрочитано» (readStateUserByMailbox).
     *
     * Без этого кнопка врёт: РОП или админ, открыв ящик менеджера, жмёт
     * «Прочитано», запись уходит в ЕГО состояние, а в списке отображается
     * состояние владельца — визуально не меняется ничего.
     *
     * @param  list<int>  $ids
     * @return array<int, list<int>> user_id => id писем
     */
    private function groupByStateUser(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $byMessage = EmailMessage::query()->whereKey($ids)->pluck('mailbox_id', 'id');
        $map = $this->readStateUserByMailbox(
            array_values(array_unique(array_map('intval', $byMessage->all())))
        );
        $fallback = (int) $this->user()->id;

        $out = [];
        foreach ($byMessage as $messageId => $mailboxId) {
            $out[$map[(int) $mailboxId] ?? $fallback][] = (int) $messageId;
        }

        return $out;
    }

    /**
     * Пометить прочитанным/непрочитанным от лица владельца состояния.
     * \Seen на сервере трогаем ТОЛЬКО когда состояние наше собственное:
     * IMAP-флаг личного ящика пишет лишь его владелец.
     *
     * @param  list<int>  $ids
     */
    private function applyReadState(array $ids, bool $read): void
    {
        $svc = app(MailReadService::class);
        $me = (int) $this->user()->id;

        foreach ($this->groupByStateUser($ids) as $userId => $chunk) {
            $actor = $userId === $me ? $this->user() : User::query()->find($userId);
            if (! $actor) {
                continue;
            }
            if ($read) {
                $svc->markManyRead($chunk, $actor);
            } else {
                foreach ($chunk as $id) {
                    $svc->markUnread($id, $actor);
                }
            }
            if ($userId === $me) {
                app(ImapSeenSyncService::class)->pushSeen($chunk, $this->user(), $read);
            }
        }
    }

    /** @param  list<int>  $ids */
    public function markManyRead(array $ids): void
    {
        $ids = $this->accessibleIds($ids);
        if ($ids === []) {
            return;
        }
        $this->applyReadState($ids, true);
        unset($this->threads, $this->folders, $this->mailboxes);
        $this->dispatch('mail-selection-clear');
    }

    /** @param  list<int>  $ids */
    public function markManyUnread(array $ids): void
    {
        $ids = $this->accessibleIds($ids);
        if ($ids === []) {
            return;
        }
        $this->applyReadState($ids, false);
        unset($this->threads, $this->folders, $this->mailboxes);
        $this->dispatch('mail-selection-clear');
    }

    /**
     * Перенести письма в пользовательскую папку (folderId null — во «Входящие»).
     *
     * @param  list<int>  $ids
     */
    public function moveToFolder(array $ids, ?int $folderId): void
    {
        $ids = $this->accessibleIds($ids);
        if ($ids === []) {
            return;
        }
        try {
            $n = app(MailboxFolderService::class)->moveMessages($ids, $folderId ?: null, $this->user());
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        if (in_array($this->openId, $ids, true)) {
            $this->openId = null;
            unset($this->openThread, $this->openAnchor);
        }
        unset($this->threads, $this->folders);
        $this->dispatch('mail-selection-clear');
        $target = $folderId ? MailboxFolder::query()->find($folderId)?->name : 'Входящие';
        $this->dispatch('toast', message: sprintf('%d %s → «%s».', $n, $this->pluralLetters($n), $target), type: 'success');
    }

    /* ------------------------- Папки ящика ---------------------------- */

    public function createFolder(string $name, ?int $parentId = null): void
    {
        $mailbox = $this->selectedMailbox();
        if (! $mailbox) {
            return;
        }
        try {
            $folder = app(MailboxFolderService::class)->create($mailbox, $name, $parentId ?: null, $this->user());
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->customFolders);
        $this->dispatch('toast', message: "Папка «{$folder->name}» создана.", type: 'success');
    }

    public function renameFolder(int $folderId, string $name): void
    {
        $folder = $this->accessibleFolder($folderId);
        if (! $folder) {
            return;
        }
        try {
            app(MailboxFolderService::class)->rename($folder, $name, $this->user());
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->customFolders);
    }

    public function deleteFolder(int $folderId): void
    {
        $folder = $this->accessibleFolder($folderId);
        if (! $folder) {
            return;
        }
        $name = $folder->name;
        $moved = app(MailboxFolderService::class)->delete($folder, $this->user());
        if ($this->folder === 'f:'.$folderId) {
            $this->folder = MailFolder::Inbox->value;
            $this->resetView();
        }
        unset($this->customFolders, $this->threads, $this->folders);
        $this->dispatch('toast', message: sprintf('Папка «%s» удалена, %d %s — во входящих.', $name, $moved, $this->pluralLetters($moved)), type: 'success');
    }

    /** Заголовок текущей папки (системной или пользовательской). */
    #[Computed]
    public function currentFolderLabel(): string
    {
        $customId = $this->customFolderId();
        if ($customId !== null) {
            foreach ($this->customFolders as $f) {
                if ($f['id'] === $customId) {
                    return $f['name'];
                }
            }
        }

        return MailFolder::tryFromOrDefault($this->folder)->label();
    }

    /** Дерево пользовательских папок выбранного ящика (с бейджами). */
    #[Computed]
    public function customFolders(): array
    {
        $mailbox = $this->selectedMailbox();

        return $mailbox ? app(MailboxFolderService::class)->tree($mailbox, $this->user()) : [];
    }

    /** id пользовательской папки из $this->folder (`f:<id>`), иначе null. */
    public function customFolderId(): ?int
    {
        return MailboxFolder::idFromKey($this->folder);
    }

    /**
     * Имена пользовательских папок активных ящиков (для чипа «в какой папке
     * письмо» в результатах поиска и в режиме «письма заявки»).
     *
     * @return array<int,string>
     */
    #[Computed]
    public function folderNames(): array
    {
        return MailboxFolder::query()
            ->whereIn('mailbox_id', $this->activeMailboxIds((bool) $this->requestId))
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /** Подпись режима поиска для шапки списка. */
    #[Computed]
    public function searchScopeLabel(): ?string
    {
        if (! $this->isSearching()) {
            return null;
        }
        if ($this->searchIn === 'inbox') {
            return 'Поиск · Входящие';
        }
        $fid = MailboxFolder::idFromKey($this->searchIn);
        if ($fid !== null) {
            return 'Поиск · '.($this->folderNames[$fid] ?? 'папка');
        }

        return 'Поиск · все папки';
    }

    private function selectedMailbox(): ?Mailbox
    {
        if (! $this->selectedMailboxId
            || ! app(MailboxAccessService::class)->canAccessMailbox($this->user(), $this->selectedMailboxId)) {
            return null;
        }

        return Mailbox::query()->find($this->selectedMailboxId);
    }

    private function accessibleFolder(int $folderId): ?MailboxFolder
    {
        $folder = MailboxFolder::query()->find($folderId);
        if (! $folder || ! app(MailboxAccessService::class)->canAccessMailbox($this->user(), (int) $folder->mailbox_id)) {
            return null;
        }

        return $folder;
    }

    /** Отфильтровать id писем по доступным ящикам. @param list<int> $ids @return list<int> */
    private function accessibleIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $allowed = EmailMessage::query()
            ->whereIn('mailbox_id', app(MailboxAccessService::class)->mailboxIdsFor($this->user()))
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Массовое действие «молча ничего не делает» выглядит как сломанная
        // кнопка. Если список пришёл, а после проверки доступа опустел —
        // оставляем след, иначе такое не диагностируется.
        if ($allowed === []) {
            Log::info('Mail\Client: bulk action got no accessible ids', [
                'user_id' => $this->user()?->id,
                'requested' => count($ids),
            ]);
        }

        return $allowed;
    }

    private function pluralLetters(int $n): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) {
            return 'письмо';
        }
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) {
            return 'письма';
        }

        return 'писем';
    }

    public function closeReading(): void
    {
        $this->openId = null;
        unset($this->openThread, $this->openAnchor);
    }

    /* ----------------------------- Метки ----------------------------- */

    /** Метки ТЕКУЩЕГО пользователя: набор личный, чужие не показываем. */
    #[Computed]
    public function labels(): Collection
    {
        return MailLabel::query()->ownedBy($this->user())
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Сколько писем под каждой моей меткой в доступных ящиках. @return array<int,int> */
    #[Computed]
    public function labelCounts(): array
    {
        return app(MessageLabelService::class)->counts(
            app(MailboxAccessService::class)->mailboxIdsFor($this->user()),
            $this->user(),
        );
    }

    /** Метка по id — только своя; чужую действие просто не найдёт. */
    private function myLabel(int $labelId): ?MailLabel
    {
        return MailLabel::query()->ownedBy($this->user())->whereKey($labelId)->first();
    }

    public function filterByLabel(?int $labelId): void
    {
        // Фильтр только по своей метке: id из чужого набора молча сбрасываем.
        $this->labelId = $labelId && $this->myLabel($labelId) !== null ? $labelId : null;
        $this->notice = null;
        $this->resetView();
    }

    public function dismissNotice(): void
    {
        $this->notice = null;
    }

    /** Переключить метку на письме (контекстное меню строки). */
    public function toggleLabel(int $messageId, int $labelId): void
    {
        $message = $this->findAccessible($messageId);
        $label = $this->myLabel($labelId);
        if (! $message || ! $label) {
            return;
        }
        app(MessageLabelService::class)->toggle($message, $label, $this->user());
        unset($this->threads, $this->labelCounts, $this->openThread);
    }

    /**
     * Повесить/снять метку на выделенных письмах.
     *
     * @param  list<int>  $ids
     */
    public function labelMany(array $ids, int $labelId, bool $on): void
    {
        $ids = $this->accessibleIds($ids);
        $label = $this->myLabel($labelId);
        if ($ids === [] || ! $label) {
            return;
        }
        $n = app(MessageLabelService::class)->apply($ids, $label, $on, $this->user());
        unset($this->threads, $this->labelCounts, $this->openThread);
        $this->notice = $on
            ? sprintf('Метка «%s» — на %d %s.', $label->name, $n, $this->pluralLetters($n))
            : sprintf('Метка «%s» снята с %d %s.', $label->name, $n, $this->pluralLetters($n));
        $this->dispatch('mail-selection-clear');
    }

    /**
     * Создать метку и сразу повесить её на письма (если переданы).
     *
     * @param  list<int>  $ids
     */
    public function createLabel(string $name, ?string $color = null, array $ids = []): void
    {
        $label = app(MessageLabelService::class)->findOrCreate($name, $color, $this->user());
        if ($label === null) {
            return;
        }
        unset($this->labels, $this->labelCounts);
        if ($ids !== []) {
            $this->labelMany($ids, $label->id, true);

            return;
        }
        $this->notice = "Метка «{$label->name}» создана.";
    }

    public function renameLabel(int $labelId, string $name): void
    {
        $label = $this->myLabel($labelId);
        if (! $label) {
            return;
        }
        $ok = app(MessageLabelService::class)->rename($label, $name);
        unset($this->labels, $this->threads, $this->openThread);
        if (! $ok) {
            $this->notice = 'Метка с таким именем уже есть — переименование отменено.';
        }
    }

    public function recolorLabel(int $labelId, string $color): void
    {
        $label = $this->myLabel($labelId);
        if (! $label) {
            return;
        }
        app(MessageLabelService::class)->recolor($label, $color);
        unset($this->labels, $this->threads, $this->openThread);
    }

    public function deleteLabel(int $labelId): void
    {
        $label = $this->myLabel($labelId);
        if (! $label) {
            return;
        }
        $name = $label->name;
        app(MessageLabelService::class)->delete($label);
        if ($this->labelId === $labelId) {
            $this->labelId = null;
        }
        unset($this->labels, $this->labelCounts, $this->threads, $this->openThread);
        $this->notice = "Метка «{$name}» удалена у всех писем.";
    }

    /* ------------------------ Ручная синхронизация ------------------------ */

    /**
     * Принудительно синхронизировать выбранный ящик: письма приходят сами раз
     * в 2 минуты (mail:sync в расписании), но иногда ждать нельзя. Троттл —
     * чтобы кнопка не превращалась в способ забить очередь.
     */
    /** До этого момента список обновляется часто — сразу после кнопки синка. */
    public ?int $fastPollUntil = null;

    /** Сколько секунд держим частый шаг после нажатия. */
    private const FAST_POLL_SECONDS = 30;

    /**
     * Шаг автообновления списка. Обычно 30 секунд, сразу после запуска
     * синхронизации — 3: ящик читается около 17 секунд, и при редком шаге
     * нажавший кнопку человек видит результат много позже, чем он готов.
     */
    #[Computed]
    public function pollInterval(): string
    {
        return $this->fastPollUntil !== null && $this->fastPollUntil > time() ? '3s' : '30s';
    }

    public function syncNow(): void
    {
        $mailboxId = (int) $this->selectedMailboxId;
        if ($mailboxId <= 0 || ! app(MailboxAccessService::class)->canAccessMailbox($this->user(), $mailboxId)) {
            return;
        }

        $key = 'mail-sync-now:'.$mailboxId;
        if (! Cache::add($key, 1, now()->addSeconds(self::SYNC_THROTTLE_SECONDS))) {
            $this->notice = 'Синхронизация уже идёт — подождите несколько секунд.';

            return;
        }

        foreach (['inbox', 'sent'] as $folderType) {
            dispatch(new SyncMailboxFolderJob($mailboxId, $folderType));
        }
        $this->syncedAt = now()->format('H:i');
        // Полминуты обновляем список часто: ящик читается около 17 секунд, и
        // при обычном шаге результат нажатия был бы виден сильно позже.
        $this->fastPollUntil = time() + self::FAST_POLL_SECONDS;
        unset($this->pollInterval);
        $this->notice = 'Синхронизация запущена — новые письма появятся в списке в течение полуминуты.';
    }

    /**
     * Может ли этот человек превратить письмо в заявку.
     *
     * Тот же круг, что в «Авто-отклонённых»: РОП, секретарь, директорат,
     * админ — и сам менеджер, чью почту он читает. Менеджер видит письмо
     * целиком и понимает про него больше автомата, запрещать ему исправлять
     * разбор незачем.
     */
    #[Computed]
    public function canPromote(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasAnyRole([
            Role::Manager->value,
            Role::HeadOfSales->value,
            Role::Secretary->value,
            Role::Director->value,
            Role::Admin->value,
        ]);
    }

    /**
     * «Это заявка!» — система ошиблась (постпродажа, «не заявка», спорный
     * разбор), а письмо на самом деле клиентский запрос.
     */
    public function promoteToRequest(int $messageId): void
    {
        if (! $this->canPromote) {
            return;
        }

        $email = $this->findAccessible($messageId);
        if ($email === null) {
            $this->notice = 'Письмо не найдено или недоступно.';

            return;
        }

        // Переписка с поставщиками — не клиентская заявка ни автоматом, ни
        // руками: личный ящик снабженца и общий rfq@, куда идут копии внешних
        // запросов, оба минуют клиентский конвейер по этой же причине.
        if ($email->mailbox?->isProcurementMailbox()
            || app(SupplierCcInboxService::class)->isRfqInboxMessage($email)) {
            $this->notice = 'Это переписка с поставщиком — клиентская заявка из неё не создаётся.';

            return;
        }

        try {
            $request = app(EmailToRequestPromoter::class)
                ->promote($email, $this->user()?->id, 'manual_create_request_from_mail');
        } catch (\DomainException $e) {
            $this->notice = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            Log::error('Mail\Client: не удалось создать заявку из письма', [
                'email_message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            $this->notice = 'Не удалось создать заявку — подробности в логе.';

            return;
        }

        unset($this->threads, $this->openThread, $this->openAnchor, $this->autoQuotes);
        $this->notice = "Создана заявка {$request->internal_code}. Идёт разбор позиций и назначение менеджера.";
    }

    public function toggleFlag(int $id): void
    {
        $email = $this->findAccessible($id);
        if (! $email) {
            return;
        }
        // Флаг берётся из того же ustate, что и прочитанность, — значит и
        // ставить его надо от лица владельца состояния, иначе флаг в списке
        // не появится.
        $stateUser = array_key_first($this->groupByStateUser([$id]));
        $actor = $stateUser === (int) $this->user()->id
            ? $this->user()
            : User::query()->find($stateUser);
        app(MailReadService::class)->toggleFlag($id, $actor ?? $this->user());
        unset($this->threads, $this->folders);
    }

    public function markUnread(int $id): void
    {
        $email = $this->findAccessible($id);
        if (! $email) {
            return;
        }
        $this->applyReadState([$id], false);
        unset($this->threads, $this->folders, $this->mailboxes);
        $this->notice = 'Письмо помечено непрочитанным.';
    }

    /** Удалить свой черновик прямо из треда/папки. */
    public function deleteDraft(int $id): void
    {
        $draft = EmailMessage::query()
            ->where('is_draft', true)
            ->where('draft_author_user_id', $this->user()->id)
            ->whereKey($id)
            ->first();
        if (! $draft) {
            return;
        }
        app(EmailDraftService::class)->delete($draft);
        unset($this->threads, $this->folders, $this->mailboxes, $this->openThread);
        $this->dispatch('toast', message: 'Черновик удалён.', type: 'success');
    }

    /* --- Открытие композера: через сервер + ->to(Composer) — гарантированная
       доставка события во вложенный компонент (клиентский $dispatch в nested
       мог не доходить). --- */
    public function reply(int $messageId): void
    {
        $this->dispatch('mail-open-reply', messageId: $messageId)->to(Composer::class);
    }

    public function replyAll(int $messageId): void
    {
        $this->dispatch('mail-open-reply-all', messageId: $messageId)->to(Composer::class);
    }

    public function forward(int $messageId): void
    {
        $this->dispatch('mail-open-forward', messageId: $messageId)->to(Composer::class);
    }

    public function compose(int $mailboxId): void
    {
        $this->dispatch('mail-open-compose', mailboxId: $mailboxId)->to(Composer::class);
    }

    public function continueDraft(int $draftId): void
    {
        $this->dispatch('mail-open-draft', draftId: $draftId)->to(Composer::class);
    }

    /** Композер отправил/удалил черновик → обновить список и счётчики. */
    #[On('mail-sent')]
    public function onMailSent(): void
    {
        unset($this->threads, $this->folders, $this->mailboxes, $this->openThread, $this->openAnchor);
    }

    /* --------------------------- Computed data --------------------------- */

    /**
     * Доступные ящики с типом (personal|shared|delegated) и непрочитанным.
     *
     * @return Collection<int, array{id:int,name:string,email:string,kind:string,unread:int,error:bool}>
     */
    #[Computed]
    public function mailboxes(): Collection
    {
        $user = $this->user();
        $svc = app(MailboxAccessService::class);
        $boxes = $svc->mailboxesFor($user);
        $ids = $boxes->pluck('id')->all();

        $unread = $this->unreadByMailbox($ids);

        return $boxes->map(fn ($m) => [
            'id' => (int) $m->id,
            'name' => $m->name ?: ($m->owner?->name ?? $m->email),
            'email' => $m->email,
            'kind' => $svc->kindOf($m, $user),
            'unread' => (int) ($unread[$m->id] ?? 0),
            'error' => $m->last_error_at !== null,
        ])->values();
    }

    /** @return array{personal: ?array, shared: array, delegated: array} */
    #[Computed]
    public function mailboxGroups(): array
    {
        $boxes = $this->mailboxes;
        $selected = $this->selectedMailboxId;

        // Выбранный ящик НЕ исключаем из списков — показываем и подсвечиваем
        // активным (blade сверяет с selectedMailboxId).
        return [
            'current' => $boxes->firstWhere('id', $selected),
            'shared' => $boxes->where('kind', 'shared')->values()->all(),
            'delegated' => $boxes->where('kind', 'delegated')->values()->all(),
            'personalOthers' => $boxes->where('kind', 'personal')->values()->all(),
        ];
    }

    /**
     * Папки с бейджами (для выбранного ящика).
     *
     * @return array<int, array{key:string,label:string,count:?int,unread:bool,active:bool}>
     */
    #[Computed]
    public function folders(): array
    {
        $out = [];
        foreach (MailFolder::ordered() as $f) {
            $count = $this->folderBadge($f);
            $out[] = [
                'key' => $f->value,
                'label' => $f->label(),
                'count' => $count,
                'unread' => $f->showsUnread(),
                'active' => $this->folder === $f->value,
            ];
        }

        return $out;
    }

    /**
     * Список тредов (в Фазе 1 — плоский список писем выбранной папки, дедуп
     * Inbox/Sent-копий по message_id; полный тред разворачивается в чтении).
     *
     * @return Collection<int, EmailMessage>
     */
    #[Computed]
    public function threads(): Collection
    {
        $uid = (int) ($this->user()?->id ?? 0);

        return $this->folderQuery(MailFolder::tryFromOrDefault($this->folder))
            // Узкий select: строка списка = шапка + сниппет, тела не нужны.
            // С `email_messages.*` уезжали body_html/body_plain/raw_source всех
            // 41 письма по SSL с облачной БД — замер на проде 573 мс против
            // 27 мс (сама выборка в PG — 30 мс, остальное транспорт+гидрация).
            ->select([
                'email_messages.id',
                'email_messages.subject',
                'email_messages.from_name',
                'email_messages.from_email',
                // Получатель нужен строке списка: в «Отправленных» и
                // «Черновиках» отправитель всегда сам владелец ящика, полезен
                // именно адресат. См. counterparty() в client.blade.php.
                'email_messages.to_recipients',
                'email_messages.is_draft',
                'email_messages.sent_at',
                'email_messages.direction',
                'email_messages.category',
                'email_messages.related_request_id',
                'email_messages.mailbox_folder_id',
                'ustate.read_at as my_read_at',
                'ustate.flagged_at as my_flagged_at',
            ])
            ->selectRaw('LEFT(email_messages.body_plain, 200) as body_plain')
            ->with('relatedRequest:id,internal_code,status,onec_number')
            // Только СВОИ метки: набор личный, чужие чипы в строке не нужны.
            ->with(['labels' => fn ($q) => $q->select('mail_labels.id', 'name', 'color')
                ->where('mail_labels.owner_user_id', $uid)])
            ->withCount('attachments')
            ->orderByRaw('email_messages.sent_at DESC NULLS LAST')
            ->orderByDesc('email_messages.id')
            ->limit($this->perPage + 1) // +1 → знаем, есть ли ещё
            ->get();
    }

    /**
     * По каким заявкам из списка система уже посчитала КП. Метка в строке —
     * чтобы менеджер видел это в почте, не открывая заявку.
     *
     * @return Collection<int, AutoQuoteSnapshot>
     */
    #[Computed]
    public function autoQuotes()
    {
        return app(AutoQuoteOfferService::class)->readyForMany(
            $this->threads->pluck('related_request_id')->filter()->map(fn ($id) => (int) $id)->all(),
        );
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->threads->count() > $this->perPage;
    }

    #[Computed]
    public function totalCount(): int
    {
        return $this->folderQuery(MailFolder::tryFromOrDefault($this->folder))->count();
    }

    /** Письма открытого треда (для панели чтения). @return Collection<int, EmailMessage> */
    #[Computed]
    public function openThread(): Collection
    {
        if (! $this->openId) {
            return collect();
        }
        $anchor = $this->findAccessible($this->openId);
        if (! $anchor) {
            return collect();
        }

        return $this->buildThread($anchor)->loadMissing('attachments');
    }

    /** Якорное (кликнутое) письмо треда. */
    #[Computed]
    public function openAnchor(): ?EmailMessage
    {
        return $this->openId ? $this->findAccessible($this->openId) : null;
    }

    /* ------------------------------ Queries ------------------------------ */

    /** ID активного ящика (обёрнут в массив для whereIn). @return array<int,int> */
    private function activeMailboxIds(bool $allMailboxes = false): array
    {
        // Фильтр «письма заявки» — по всем доступным ящикам (переписка заявки
        // лежит и в личном ящике менеджера, и в общем info@).
        if ($allMailboxes) {
            return app(MailboxAccessService::class)->mailboxIdsFor($this->user());
        }
        if ($this->selectedMailboxId
            && app(MailboxAccessService::class)->canAccessMailbox($this->user(), $this->selectedMailboxId)) {
            return [$this->selectedMailboxId];
        }

        return app(MailboxAccessService::class)->mailboxIdsFor($this->user());
    }

    /**
     * Базовый запрос по активному ящику + персональный read/flag через leftJoin.
     * НЕ фильтрует is_draft (это делает папка) и НЕ фильтрует направление.
     */
    private function baseQuery(bool $allMailboxes = false): Builder
    {
        $mailboxIds = $this->activeMailboxIds($allMailboxes);

        return EmailMessage::query()
            ->whereIn('email_messages.mailbox_id', $mailboxIds)
            ->tap(fn (Builder $q) => $this->hideCopiesWhoseOriginalIsListed($q, $mailboxIds))
            ->tap(fn (Builder $q) => $this->hideReassignedCopies($q))
            ->tap(fn (Builder $q) => $this->hideGoneFromServer($q, $mailboxIds))
            ->tap(fn (Builder $q) => $this->joinReadState($q, $mailboxIds))
            ->select('email_messages.*', 'ustate.read_at as my_read_at', 'ustate.flagged_at as my_flagged_at');
    }

    /**
     * Личные ящики, зеркалящие сервер (ImapFolderSyncService): входящее письмо
     * с imap_uid = NULL уже не лежит ни в одной серверной папке, которую мы
     * видим (удалено / в корзине / спаме) — в почте mzCorp его тоже нет.
     * Переписка при этом остаётся в карточке заявки. Общие ящики не трогаем.
     *
     * @param  list<int>  $mailboxIds
     */
    private function hideGoneFromServer(Builder $q, array $mailboxIds): void
    {
        $sync = app(ImapFolderSyncService::class);
        $synced = Mailbox::query()->whereIn('id', $mailboxIds)->get()
            ->filter(fn ($m) => $sync->isServerSynced($m))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($synced === []) {
            return;
        }
        $q->where(function (Builder $w) use ($synced) {
            $w->whereNotIn('email_messages.mailbox_id', $synced)
                ->orWhere('email_messages.direction', '!=', MailDirection::Inbound->value)
                ->orWhereNotNull('email_messages.imap_uid');
        });
    }

    /**
     * Копии писем переданных заявок: MailReassignArchiverService переложил их
     * в Яндексе из INBOX бывшего менеджера в MZ|Reassigned (folder в БД тот же)
     * — в его почте mzCorp они тоже не показываются. Переписка остаётся в
     * карточке заявки у нового менеджера.
     */
    private function hideReassignedCopies(Builder $q): void
    {
        $q->whereNotIn('email_messages.folder', MailReassignArchiverService::archivePaths());
    }

    /**
     * Чья прочитанность/флаг показываются по каждому ящику: у ЛИЧНОГО ящика с
     * владельцем — владельца (одна «правда» на ящик, она же синхронизирована с
     * \Seen на сервере; директор/РОП, заглянув в ящик, видят её и не меняют),
     * у общих и делегированных без владельца — текущего пользователя.
     *
     * @param  list<int>  $mailboxIds
     * @return array<int,int> mailbox_id => user_id
     */
    private function readStateUserByMailbox(array $mailboxIds): array
    {
        $uid = (int) $this->user()->id;
        $owners = Mailbox::query()
            ->whereIn('id', $mailboxIds)
            ->where('type', MailboxType::Personal->value)
            ->whereNotNull('owner_user_id')
            ->pluck('owner_user_id', 'id');

        $map = [];
        foreach ($mailboxIds as $id) {
            $map[(int) $id] = (int) ($owners[$id] ?? $uid);
        }

        return $map;
    }

    /** leftJoin email_message_user_states как `ustate` с правилом readStateUserByMailbox(). */
    private function joinReadState(Builder $q, array $mailboxIds): void
    {
        $map = $this->readStateUserByMailbox($mailboxIds);
        $fallback = (int) $this->user()->id;

        $q->leftJoin('email_message_user_states as ustate', function ($j) use ($map, $fallback) {
            $j->on('ustate.email_message_id', '=', 'email_messages.id');
            $distinct = array_values(array_unique($map));
            if (count($distinct) <= 1) {
                $j->where('ustate.user_id', '=', $distinct[0] ?? $fallback);

                return;
            }
            // Литералы, а не bindings: PostgreSQL выводит тип нетипизированных
            // параметров в CASE как text → «operator does not exist: bigint = text».
            // Значения — только целые id (int-cast выше), инъекция невозможна.
            $cases = [];
            foreach ($map as $mailboxId => $userId) {
                $cases[] = sprintf('WHEN %d THEN %d', (int) $mailboxId, (int) $userId);
            }
            $j->whereRaw(sprintf(
                'ustate.user_id = (CASE email_messages.mailbox_id %s ELSE %d END)',
                implode(' ', $cases),
                $fallback,
            ));
        });
    }

    /** Применить фильтр папки + поиск. */
    private function folderQuery(MailFolder $folder, bool $ignoreRequestFilter = false): Builder
    {
        $uid = (int) $this->user()->id;
        $requestMode = $this->requestId && ! $ignoreRequestFilter;
        $q = $this->baseQuery(allMailboxes: $requestMode);

        // Фильтр по метке применяется поверх любой папки: метка — это срез,
        // а не место, письмо остаётся лежать там, где лежало.
        if ($this->labelId !== null && ! $ignoreRequestFilter) {
            $q->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('email_message_labels as eml')
                    ->whereColumn('eml.email_message_id', 'email_messages.id')
                    ->where('eml.mail_label_id', $this->labelId);
            });
        }

        // «Только непрочитанные» — такой же срез поверх папки, как метка.
        // Исходящие под него не попадают никогда: у своего письма нет и не
        // может быть отметки о прочтении, а видеть их в этом фильтре незачем.
        if ($this->unreadOnly && ! $ignoreRequestFilter) {
            $q->where('email_messages.direction', MailDirection::Inbound)
                ->where(function (Builder $w) {
                    $w->whereNull('ustate.read_at');
                    // Открытое письмо помечается прочитанным в тот же миг —
                    // исчезать из списка у человека под курсором оно не должно.
                    if ($this->openId !== null) {
                        $w->orWhere('email_messages.id', $this->openId);
                    }
                });
        }

        // Режим «письма заявки»: обе стороны переписки, без папок; ящики — все
        // доступные. Поиск поверх работает. Бейджи папок в сайдбаре считаются
        // без этого фильтра и по выбранному ящику (ignoreRequestFilter).
        if ($requestMode) {
            $q->where('email_messages.is_draft', false)
                ->where('email_messages.related_request_id', $this->requestId);
            $this->applySearch($q);

            return $q;
        }

        // Поиск идёт по всем папкам ящика (как в почтовике), если не выбран
        // конкретный «искать в»: 'inbox' — только не разложенное, 'f:<id>' — папка.
        $searching = $this->isSearching() && ! $ignoreRequestFilter;
        $searchFolderId = MailboxFolder::idFromKey($this->searchIn);
        $notFiled = function (Builder $b) use ($searching, $searchFolderId): Builder {
            if (! $searching || $this->searchIn === 'inbox') {
                return $b->whereNull('email_messages.mailbox_folder_id');
            }
            if ($searchFolderId !== null) {
                return $b->where('email_messages.mailbox_folder_id', $searchFolderId);
            }

            return $b; // 'all' — все папки
        };

        // Пользовательская папка (`f:<id>`): всё, что в неё положили, обе стороны.
        $customId = $this->customFolderId();
        if ($customId !== null && ! $ignoreRequestFilter) {
            $q->where('email_messages.is_draft', false);
            if ($searching) {
                $notFiled($q);
            } else {
                $q->where('email_messages.mailbox_folder_id', $customId);
            }
            $this->applySearch($q);

            return $q;
        }

        // Письма, разложенные по пользовательским папкам, из системных папок
        // уходят (как в почтовике); «Помеченные» и «Черновики» — не папки-места.

        match ($folder) {
            MailFolder::Inbox => $notFiled($q)->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Inbound->value),
            MailFolder::Sent => $notFiled($q)->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Outbound->value),
            MailFolder::Drafts => $q->where('email_messages.is_draft', true)
                ->where('email_messages.draft_author_user_id', $uid),
            MailFolder::Flagged => $q->where('email_messages.is_draft', false)
                ->whereNotNull('ustate.flagged_at'),
            MailFolder::WithRequest => $notFiled($q)->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Inbound->value)
                ->whereNotNull('email_messages.related_request_id'),
            MailFolder::WithoutRequest => $notFiled($q)->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Inbound->value)
                ->whereNull('email_messages.related_request_id'),
        };

        $this->applySearch($q);

        return $q;
    }

    private function applySearch(Builder $q): void
    {
        $s = trim($this->search);
        if ($s === '') {
            return;
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
        $q->where(function ($w) use ($like) {
            $w->where('email_messages.subject', 'ilike', $like)
                ->orWhere('email_messages.from_email', 'ilike', $like)
                ->orWhere('email_messages.from_name', 'ilike', $like)
                ->orWhere('email_messages.body_plain', 'ilike', $like);
        });
    }

    /** Бейдж папки: непрочитанные для inbox/без-заявки, иначе общее число. */
    private function folderBadge(MailFolder $folder): ?int
    {
        if ($folder->showsUnread()) {
            return (clone $this->folderQuery($folder, ignoreRequestFilter: true))->whereNull('ustate.read_at')->count();
        }
        if (in_array($folder, [MailFolder::Drafts, MailFolder::Flagged], true)) {
            $n = $this->folderQuery($folder, ignoreRequestFilter: true)->count();

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /** Непрочитанные по каждому ящику (для бейджей переключателя). @return array<int,int> */
    private function unreadByMailbox(array $mailboxIds): array
    {
        if ($mailboxIds === []) {
            return [];
        }

        // Бейдж ящика = то, что физически лежит в ЭТОМ ящике (как и список при
        // выборе одного ящика), поэтому копии здесь не прячем: копия и её
        // оригинал никогда не лежат в одном ящике.
        return EmailMessage::query()
            ->whereIn('email_messages.mailbox_id', $mailboxIds)
            ->where('email_messages.is_draft', false)
            ->where('email_messages.direction', MailDirection::Inbound->value)
            ->tap(fn (Builder $q) => $this->hideReassignedCopies($q))
            ->tap(fn (Builder $q) => $this->hideGoneFromServer($q, $mailboxIds))
            ->tap(fn (Builder $q) => $this->joinReadState($q, $mailboxIds))
            ->whereNull('ustate.read_at')
            ->groupBy('email_messages.mailbox_id')
            ->selectRaw('email_messages.mailbox_id, COUNT(*) as c')
            ->pluck('c', 'mailbox_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Собрать тред вокруг письма: по заявке (если привязано) иначе по заголовкам. */
    private function buildThread(EmailMessage $anchor): Collection
    {
        $user = $this->user();

        if ($anchor->related_request_id) {
            return EmailMessage::query()
                ->visibleTo($user)
                ->with('relatedRequest:id,internal_code,status,onec_number')
                ->where('related_request_id', $anchor->related_request_id)
                // Не тащить кросс-ящиковые тех.копии (одно письмо в личном INBOX
                // менеджера + в общем ящике) — иначе тред двоится.
                ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
                ->orderByRaw('sent_at ASC NULLS LAST')
                ->orderBy('id')
                ->get()
                // Дедуп по message_id (Inbox+Sent-копии одного письма), как в
                // SharedMailService::threadFor; строки без message_id уникальны.
                ->unique(fn (EmailMessage $m) => ($mid = trim((string) $m->message_id)) !== ''
                    ? mb_strtolower($mid)
                    : 'row-'.$m->id)
                ->values();
        }

        return app(SharedMailService::class)->threadFor($anchor);
    }

    /**
     * Спрятать кросс-ящиковую копию письма ТОЛЬКО если её оригинал и так попадает
     * в текущую выборку ящиков. Иначе — показать копию как обычное письмо.
     *
     * Маркер `cross_mailbox_copy_of` ставится не только техническим копиям от
     * DeliverToManagerInboxJob, но и естественным: клиент пишет «To: info@,
     * Andrey.Vasukhno@» → письмо лежит в ОБОИХ ящиках, и «оригиналом» становится
     * тот, чей sync успел первым (личный ящик синкается раньше общего на ~40 с).
     * Безусловный `IS NULL` при выбранном ящике info@ прятал такое письмо
     * целиком — оно есть в Yandex, есть в заявке, а в разделе «Почта → info@»
     * его нет (кейс sminex 04.09.2026, msg#96954 ← копия #96951).
     *
     * Правило: один выбранный ящик → показываем всё, что в нём физически лежит
     * (копия и оригинал в одном ящике невозможны — uniq (mailbox, folder,
     * message_id)); все ящики → копия прячется, только если её оригинал тоже
     * в списке (иначе письмо задвоится), а если оригинал в недоступном
     * пользователю ящике — копия остаётся единственным представителем письма.
     *
     * @param  array<int,int>  $mailboxIds
     */
    private function hideCopiesWhoseOriginalIsListed(Builder $q, array $mailboxIds): Builder
    {
        // Один ящик: копия и её оригинал (или две копии одного оригинала) в
        // одном ящике невозможны — uniq (mailbox_id, folder, message_id).
        // Показываем всё, что физически лежит в ящике, без фильтра (20 мс).
        if (count($mailboxIds) < 2) {
            return $q;
        }
        $placeholders = implode(',', array_fill(0, count($mailboxIds), '?'));

        // Несколько ящиков: множество «скрыть» — копии, у которых в выборке есть
        // оригинал (lookup по PK) ИЛИ более ранняя копия того же оригинала
        // (оригинал в недоступном ящике, копии в двух доступных — оставляем
        // одну, с меньшим id). Множество НЕКОРРЕЛИРОВАННОЕ — строится один
        // раз (~9.7k строк, ~90 мс на проде), а не на каждую строку списка:
        // коррелированный NOT EXISTS давал оценку стоимости 500k+ → Postgres
        // включал JIT и тратил 420 мс на компиляцию 30-мс запроса.
        // Второй EXISTS ходит по частичному индексу
        // email_messages_cross_mailbox_copy_of_idx (миграция 2026_09_04_120000):
        // предикат `~ '^[0-9]+$'` обязан совпадать с индексом дословно.
        $c = "(c.detected_artifacts->>'cross_mailbox_copy_of')";

        return $q->whereRaw(
            "email_messages.id NOT IN (
                SELECT c.id FROM email_messages c
                WHERE c.mailbox_id IN ({$placeholders})
                  AND {$c} ~ '^[0-9]+\$'
                  AND (
                      EXISTS (
                          SELECT 1 FROM email_messages o
                          WHERE o.id = {$c}::bigint
                            AND o.mailbox_id IN ({$placeholders})
                      )
                      OR EXISTS (
                          SELECT 1 FROM email_messages o2
                          WHERE (o2.detected_artifacts->>'cross_mailbox_copy_of') ~ '^[0-9]+\$'
                            AND (o2.detected_artifacts->>'cross_mailbox_copy_of')::bigint = {$c}::bigint
                            AND o2.id < c.id
                            AND o2.mailbox_id IN ({$placeholders})
                      )
                  )
            )",
            array_merge(array_values($mailboxIds), array_values($mailboxIds), array_values($mailboxIds)),
        );
    }

    /** Найти письмо в пределах доступных ящиков (защита доступа). */
    private function findAccessible(int $id): ?EmailMessage
    {
        return EmailMessage::query()
            ->whereIn('mailbox_id', app(MailboxAccessService::class)->mailboxIdsFor($this->user()))
            ->whereKey($id)
            ->first();
    }

    public function render()
    {
        return view('livewire.mail.client');
    }
}
