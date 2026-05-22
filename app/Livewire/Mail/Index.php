<?php

namespace App\Livewire\Mail;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Почта» — read-only листинг всех email_messages по всем ящикам.
 *
 * Доступ: head_of_sales / secretary / director / admin (НЕ manager).
 * Менеджеры работают со своей почтой через карточку заявки.
 *
 * Фильтры (URL-state):
 *   - mbox: id ящика (null = все)
 *   - dir : '' | 'inbound' | 'outbound'
 *   - period: today | 7d | 30d | 90d | all (default 7d)
 *   - link: all | linked | unlinked
 *
 * Inline-превью: при клике на строку раскрывается body (iframe srcdoc для
 * HTML, <pre> для plain) + attachments. body грузится отдельным fetch'ем —
 * чтобы не таскать сотни KB на каждую строку пагинации.
 *
 * Линк на заявку: если у письма related_request_id != null, в строке
 * чип-линк на Detail.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'mbox')]
    public ?int $mailboxId = null;

    #[Url(as: 'dir')]
    public string $direction = '';

    #[Url(as: 'period')]
    public string $period = '7d';

    #[Url(as: 'link')]
    public string $linkage = 'all';

    /**
     * Показывать ли cross-mailbox копии писем. По умолчанию false:
     * `DeliverToManagerInboxJob` дублирует входящее в личный INBOX
     * менеджера — это техническая копия, не отдельное письмо.
     * Detail.php их прячет через `detected_artifacts->>'cross_mailbox_copy_of' IS NULL`,
     * здесь тот же фильтр. Toggle позволяет РОПу при необходимости
     * аудитировать сами копии (debug-режим).
     */
    #[Url(as: 'copies')]
    public bool $showCopies = false;

    /** id раскрытого письма (для inline-превью). */
    #[Url(as: 'expand')]
    public ?int $expandedId = null;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Secretary->value,
            Role::Director->value,
            Role::Admin->value,
        ]), 403, 'Раздел «Почта» доступен только РОПу, секретарю, директорату и админам.');
    }

    /* ------------------------------- Filters -------------------------------- */

    public function setDirection(string $d): void
    {
        $this->direction = in_array($d, ['', 'inbound', 'outbound'], true) ? $d : '';
        $this->resetPage();
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['today', '7d', '30d', '90d', 'all'], true) ? $p : '7d';
        $this->resetPage();
    }

    public function setLinkage(string $l): void
    {
        $this->linkage = in_array($l, ['all', 'linked', 'unlinked'], true) ? $l : 'all';
        $this->resetPage();
    }

    public function toggleShowCopies(): void
    {
        $this->showCopies = ! $this->showCopies;
        $this->resetPage();
    }

    public function updatingMailboxId(): void
    {
        $this->resetPage();
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = ($this->expandedId === $id) ? null : $id;
    }

    /* ------------------------------ Computed -------------------------------- */

    #[Computed]
    public function emails()
    {
        return $this->buildQuery()
            ->select([
                'id', 'mailbox_id', 'folder', 'direction', 'subject',
                'from_email', 'from_name', 'to_recipients',
                'category', 'category_confidence',
                'sent_at', 'created_at', 'related_request_id',
            ])
            ->with([
                'mailbox:id,email,name,owner_user_id',
                'mailbox.owner:id,name',
                'relatedRequest:id,internal_code,status,assigned_user_id',
                'relatedRequest.assignedUser:id,name',
            ])
            ->withCount('attachments')
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * Список ящиков для dropdown'а фильтра. Показываем только syncable
     * (общие shared + личные с managerial-ролью владельца). Личные
     * ящики директора/секретаря/админа исключаются — их почта не входит
     * в бизнес-аудит, и сам синк их не читает (см. Mailbox::scopeSyncable).
     *
     * @return Collection<int, Mailbox>
     */
    #[Computed]
    public function mailboxesForFilter(): Collection
    {
        return Mailbox::query()
            ->syncable()
            ->select(['id', 'email', 'name', 'type', 'owner_user_id', 'is_active'])
            ->with('owner:id,name')
            ->orderBy('type')
            ->orderBy('email')
            ->get();
    }

    /**
     * Полная модель раскрытого письма (с body + attachments + relations).
     * Отдельный fetch, чтобы не таскать body для всех 50 строк пагинации.
     */
    #[Computed]
    public function expandedEmail(): ?EmailMessage
    {
        if (! $this->expandedId) {
            return null;
        }

        return EmailMessage::query()
            ->whereKey($this->expandedId)
            ->with([
                'attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
                'mailbox:id,email,name',
                'relatedRequest:id,internal_code,status',
            ])
            ->first();
    }

    /**
     * Преобразовать body_html для безопасного рендера в iframe srcdoc:
     *   - cid:NNN → route('attachments.inline', ...)
     *
     * Quoted-блоки НЕ сворачиваем (Detail.php делает collapseQuotedBlocks
     * для треда заявки — там цитаты дублируют контекст. В audit-листинге
     * «Почты» полезно видеть всю цепочку как есть).
     */
    public function bodyHtmlFor(EmailMessage $email): ?string
    {
        if (! $email->body_html) {
            return null;
        }

        $messageId = $email->id;

        return preg_replace_callback(
            '/(src|href)\s*=\s*(["\'])cid:([^"\']+)\2/i',
            function ($m) use ($messageId) {
                $url = route('attachments.inline', [
                    'emailMessage' => $messageId,
                    'contentId' => rawurlencode($m[3]),
                ]);

                return $m[1] . '=' . $m[2] . $url . $m[2];
            },
            $email->body_html
        ) ?? $email->body_html;
    }

    /* ------------------------------- Helpers -------------------------------- */

    private function buildQuery(): Builder
    {
        $q = EmailMessage::query()->notDraft();

        // Жёсткий scope: показываем письма ТОЛЬКО из syncable-ящиков
        // (общие + личные менеджеров/РОПа). Почта директора/секретаря/админа
        // не входит в бизнес-аудит — она и в IMAP не синкается. Подзапрос
        // защищает от прямого подбора mailbox_id через URL.
        $q->whereIn('mailbox_id', Mailbox::query()->syncable()->select('id'));

        // Скрываем cross-mailbox копии (DeliverToManagerInboxJob дублирует
        // оригинал из общего ящика в личный INBOX менеджера). По умолчанию
        // показываем только оригиналы — тот же фильтр что в Detail::mount.
        if (! $this->showCopies) {
            $q->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL");
        }

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        if ($this->direction === MailDirection::Inbound->value) {
            $q->where('direction', MailDirection::Inbound->value);
        } elseif ($this->direction === MailDirection::Outbound->value) {
            $q->where('direction', MailDirection::Outbound->value);
        }

        $cutoff = match ($this->period) {
            'today' => now()->startOfDay(),
            '7d'    => now()->subDays(7),
            '30d'   => now()->subDays(30),
            '90d'   => now()->subDays(90),
            default => null, // 'all'
        };
        if ($cutoff !== null) {
            $q->where('created_at', '>=', $cutoff);
        }

        if ($this->linkage === 'linked') {
            $q->whereNotNull('related_request_id');
        } elseif ($this->linkage === 'unlinked') {
            $q->whereNull('related_request_id');
        }

        return $q;
    }

    /**
     * Категория → CSS-chip-класс. Для chip-row.
     */
    public function categoryChipClass(?string $category): string
    {
        return match ($category) {
            EmailCategory::ClientRequest->value => 'chip-ok',
            EmailCategory::ThreadReply->value   => 'chip-info',
            EmailCategory::Irrelevant->value    => 'chip-paused',
            default                             => 'chip-neutral',
        };
    }

    public function categoryLabel(?string $category): string
    {
        if ($category === null) {
            return '—';
        }

        return EmailCategory::tryFrom($category)?->label() ?? $category;
    }

    public function render()
    {
        return view('livewire.mail.index');
    }
}
