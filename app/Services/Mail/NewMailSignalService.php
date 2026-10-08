<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Глобальный сигнал «пришло новое письмо» — для любого раздела CRM, не только
 * «Почты» (опрос из layouts/app.blade.php).
 *
 * Сигналим только по ЛИЧНЫМ ящикам пользователя: своему и делегированным
 * (замещение). Общие info@/order@ в сигнал не идут: их письма маршрутизатор
 * и так раскладывает по личным ящикам менеджеров, а у РОПа/директора с
 * обзором всех ящиков сигнал по чужим был бы шумом.
 *
 * Прочитанность — владельца ящика, как в бейджах почтового клиента; число
 * непрочитанных берётся из общего с клиентом кэша (MailUnreadCounter).
 */
class NewMailSignalService
{
    /** Сколько свежих писем отдаём для показа; остальное — числом. */
    public const FRESH_LIMIT = 3;

    /** Потолок подсчёта свежих: дальше «и ещё много» не уточняем. */
    public const FRESH_COUNT_CAP = 99;

    /** Роли с почтовым клиентом — как middleware роута mail.inbox. */
    private const MAIL_CLIENT_ROLES = [
        Role::Manager->value,
        Role::HeadOfSales->value,
        Role::Admin->value,
        Role::Director->value,
    ];

    public function __construct(
        private readonly MailboxAccessService $access,
        private readonly MailUnreadCounter $counter,
    ) {}

    /**
     * Личные ящики, по которым сигналим: свой + делегированные.
     *
     * @return Collection<int, Mailbox>
     */
    public function mailboxesFor(User $user): Collection
    {
        // Сигнал ведёт в почтовый клиент — только тем, кому он открыт (роут mail.inbox).
        if (! $user->hasAnyRole(self::MAIL_CLIENT_ROLES)) {
            return collect();
        }

        return $this->access->mailboxesFor($user)
            ->filter(fn (Mailbox $m) => $m->type === MailboxType::Personal
                && $m->owner_user_id !== null
                && ((int) $m->owner_user_id === (int) $user->id
                    || $this->access->kindOf($m, $user) === 'delegated'))
            ->values();
    }

    /**
     * Сводка для опроса: сколько непрочитанных, курсор (последний id входящего)
     * и письма новее $afterId. Без $afterId (первый заход) свежих не отдаём —
     * клиент просто запоминает курсор, чтобы не сигналить о старом.
     *
     * @return array{enabled: bool, unread: int, cursor: int, fresh: list<array{id:int, from:string, subject:string, url:string}>, fresh_count: int, inbox_url?: string}
     */
    public function summary(User $user, ?int $afterId): array
    {
        $boxes = $this->mailboxesFor($user);
        if ($boxes->isEmpty()) {
            return ['enabled' => false, 'unread' => 0, 'cursor' => 0, 'fresh' => [], 'fresh_count' => 0];
        }

        $unread = 0;
        foreach ($boxes as $box) {
            $unread += $this->counter->count((int) $box->id, (int) $box->owner_user_id);
        }

        // Курсор — по всем входящим ящиков, включая прочитанные: иначе
        // прочитанное на телефоне письмо всплыло бы «новым» при следующем
        // появлении непрочитанного. Идёт по индексу (mailbox_id, id) входящих.
        $cursor = (int) EmailMessage::query()
            ->whereIn('mailbox_id', $boxes->pluck('id'))
            ->where('direction', MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->max('id');

        $fresh = [];
        $freshCount = 0;
        if ($afterId !== null && $cursor > $afterId) {
            [$fresh, $freshCount] = $this->freshSince($boxes, $afterId);
        }

        return [
            'enabled' => true,
            'unread' => $unread,
            'cursor' => $cursor,
            'fresh' => $fresh,
            'fresh_count' => $freshCount,
            'inbox_url' => route('mail.inbox'),
        ];
    }

    /**
     * Непрочитанные письма новее курсора. Зеркало истории ящиков сюда не
     * попадает (глобальный scope EmailMessage): старые шапки, догруженные
     * задним числом, — не новая почта.
     *
     * @param  Collection<int, Mailbox>  $boxes
     * @return array{0: list<array{id:int, from:string, subject:string, url:string}>, 1: int}
     */
    private function freshSince(Collection $boxes, int $afterId): array
    {
        $rows = collect();
        $count = 0;
        foreach ($boxes as $box) {
            $q = $this->counter
                ->unreadInbound((int) $box->id, (int) $box->owner_user_id, withHistory: false)
                ->where('email_messages.id', '>', $afterId);

            $count += (clone $q)->limit(self::FRESH_COUNT_CAP)->count();
            $rows = $rows->merge(
                $q->orderByDesc('email_messages.id')
                    ->limit(self::FRESH_LIMIT)
                    ->get(['email_messages.id', 'email_messages.mailbox_id', 'email_messages.from_name', 'email_messages.from_email', 'email_messages.subject'])
            );
        }

        $fresh = $rows->sortByDesc('id')->take(self::FRESH_LIMIT)->values()
            ->map(fn (EmailMessage $m) => [
                'id' => (int) $m->id,
                'from' => trim((string) ($m->from_name ?: $m->from_email)),
                'subject' => trim((string) $m->subject) !== '' ? trim((string) $m->subject) : '(без темы)',
                'url' => route('mail.inbox', ['mbox' => $m->mailbox_id, 'open' => $m->id]),
            ])
            ->all();

        return [$fresh, min($count, self::FRESH_COUNT_CAP)];
    }
}
