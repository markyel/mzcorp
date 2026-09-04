<?php

namespace App\Livewire\Mail;

use App\Enums\MailDirection;
use App\Enums\MailFolder;
use App\Enums\Role;
use App\Livewire\Concerns\RendersEmailBody;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\EmailDraftService;
use App\Services\Mail\MailboxAccessService;
use App\Services\Mail\MailReadService;
use App\Services\Mail\SharedMailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    public int $perPage = 40;

    private const PER_PAGE_STEP = 20;

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
    }

    private function canAccess(): bool
    {
        return $this->user()?->hasAnyRole([Role::Manager->value, Role::Admin->value, Role::Director->value]) ?? false;
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
        $this->resetView();
    }

    public function selectFolder(string $folder): void
    {
        $this->folder = MailFolder::tryFromOrDefault($folder)->value;
        $this->resetView();
    }

    public function updatedSearch(): void
    {
        $this->perPage = 40;
        $this->openId = null;
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

        $thread = $this->buildThread($anchor);
        app(MailReadService::class)->markManyRead($thread->pluck('id')->all(), $this->user());

        // Обновить список (снять «непрочитано») и счётчики.
        unset($this->threads, $this->folders, $this->mailboxes);
    }

    public function closeReading(): void
    {
        $this->openId = null;
        unset($this->openThread, $this->openAnchor);
    }

    public function toggleFlag(int $id): void
    {
        $email = $this->findAccessible($id);
        if (! $email) {
            return;
        }
        app(MailReadService::class)->toggleFlag($id, $this->user());
        unset($this->threads, $this->folders);
    }

    public function markUnread(int $id): void
    {
        $email = $this->findAccessible($id);
        if (! $email) {
            return;
        }
        app(MailReadService::class)->markUnread($id, $this->user());
        unset($this->threads, $this->folders, $this->mailboxes);
        $this->dispatch('toast', message: 'Помечено непрочитанным.', type: 'success');
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
                'email_messages.sent_at',
                'email_messages.direction',
                'email_messages.category',
                'email_messages.related_request_id',
                'ustate.read_at as my_read_at',
                'ustate.flagged_at as my_flagged_at',
            ])
            ->selectRaw('LEFT(email_messages.body_plain, 200) as body_plain')
            ->with('relatedRequest:id,internal_code,status')
            ->withCount('attachments')
            ->orderByRaw('email_messages.sent_at DESC NULLS LAST')
            ->orderByDesc('email_messages.id')
            ->limit($this->perPage + 1) // +1 → знаем, есть ли ещё
            ->get();
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
    private function activeMailboxIds(): array
    {
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
    private function baseQuery(): Builder
    {
        $uid = (int) $this->user()->id;
        $mailboxIds = $this->activeMailboxIds();

        return EmailMessage::query()
            ->whereIn('email_messages.mailbox_id', $mailboxIds)
            ->tap(fn (Builder $q) => $this->hideCopiesWhoseOriginalIsListed($q, $mailboxIds))
            ->leftJoin('email_message_user_states as ustate', function ($j) use ($uid) {
                $j->on('ustate.email_message_id', '=', 'email_messages.id')
                    ->where('ustate.user_id', '=', $uid);
            })
            ->select('email_messages.*', 'ustate.read_at as my_read_at', 'ustate.flagged_at as my_flagged_at');
    }

    /** Применить фильтр папки + поиск. */
    private function folderQuery(MailFolder $folder): Builder
    {
        $uid = (int) $this->user()->id;
        $q = $this->baseQuery();

        match ($folder) {
            MailFolder::Inbox => $q->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Inbound->value),
            MailFolder::Sent => $q->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Outbound->value),
            MailFolder::Drafts => $q->where('email_messages.is_draft', true)
                ->where('email_messages.draft_author_user_id', $uid),
            MailFolder::Flagged => $q->where('email_messages.is_draft', false)
                ->whereNotNull('ustate.flagged_at'),
            MailFolder::WithRequest => $q->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Inbound->value)
                ->whereNotNull('email_messages.related_request_id'),
            MailFolder::WithoutRequest => $q->where('email_messages.is_draft', false)
                ->where('email_messages.direction', MailDirection::Inbound->value)
                ->whereNull('email_messages.related_request_id'),
        };

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
            $q->where(function ($w) use ($like) {
                $w->where('email_messages.subject', 'ilike', $like)
                    ->orWhere('email_messages.from_email', 'ilike', $like)
                    ->orWhere('email_messages.from_name', 'ilike', $like)
                    ->orWhere('email_messages.body_plain', 'ilike', $like);
            });
        }

        return $q;
    }

    /** Бейдж папки: непрочитанные для inbox/без-заявки, иначе общее число. */
    private function folderBadge(MailFolder $folder): ?int
    {
        if ($folder->showsUnread()) {
            return (clone $this->folderQuery($folder))->whereNull('ustate.read_at')->count();
        }
        if (in_array($folder, [MailFolder::Drafts, MailFolder::Flagged], true)) {
            $n = $this->folderQuery($folder)->count();

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
        $uid = (int) $this->user()->id;

        // Бейдж ящика = то, что физически лежит в ЭТОМ ящике (как и список при
        // выборе одного ящика), поэтому копии здесь не прячем: копия и её
        // оригинал никогда не лежат в одном ящике.
        return EmailMessage::query()
            ->whereIn('email_messages.mailbox_id', $mailboxIds)
            ->where('email_messages.is_draft', false)
            ->where('email_messages.direction', MailDirection::Inbound->value)
            ->leftJoin('email_message_user_states as ustate', function ($j) use ($uid) {
                $j->on('ustate.email_message_id', '=', 'email_messages.id')
                    ->where('ustate.user_id', '=', $uid);
            })
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
                ->with('relatedRequest:id,internal_code,status')
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
