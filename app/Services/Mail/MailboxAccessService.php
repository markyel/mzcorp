<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Enums\Role;
use App\Models\Mailbox;
use App\Models\RequestDelegation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Какие почтовые ящики видит менеджер в разделе «Почта».
 *
 * Продуктовое решение: «всё доступное ему» —
 *   1. личные ящики, где он владелец (mailbox.owner_user_id = user.id);
 *   2. общие ящики (info@ / order@ — type=shared, синкаемые);
 *   3. делегированные — личные ящики менеджеров, у которых есть АКТИВНАЯ
 *      делегация заявок на этого пользователя (он замещающий).
 *
 * Всё в пределах scopeSyncable (неактивные/несинкаемые ящики не показываем).
 * Привилегированные роли (РОП/директор/админ) видят те же три группы для себя
 * (у них своя org-wide витрина /dashboard/mail — это другой инструмент).
 */
class MailboxAccessService
{
    /** @var array<string, Collection<int, Mailbox>> */
    private array $cache = [];

    /**
     * Все доступные ящики пользователя, сгруппированы порядком: личные → общие →
     * делегированные. С каждым — вычисленный «kind» через getAccessKind().
     *
     * @return Collection<int, Mailbox>
     */
    public function mailboxesFor(User $user): Collection
    {
        $key = 'mb_'.$user->id;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // Привилегированный обзор (админ / директорат): ВСЕ синкаемые ящики
        // (общие + личные всех менеджеров) с переключением между ними.
        if ($this->hasFullMailboxAccess($user)) {
            return $this->cache[$key] = Mailbox::query()
                ->syncable()
                ->with('owner:id,name')
                ->orderByRaw("CASE type WHEN 'shared' THEN 0 ELSE 1 END")
                ->orderBy('email')
                ->get();
        }

        $delegatedOwnerIds = $this->delegatedOwnerIds($user);

        $all = Mailbox::query()
            ->syncable()
            ->where(function ($q) use ($user, $delegatedOwnerIds) {
                $q->where('owner_user_id', $user->id)
                    ->orWhere('type', MailboxType::Shared->value);
                if ($delegatedOwnerIds !== []) {
                    $q->orWhereIn('owner_user_id', $delegatedOwnerIds);
                }
            })
            ->with('owner:id,name')
            ->orderByRaw("CASE type WHEN 'personal' THEN 0 ELSE 1 END")
            ->orderBy('email')
            ->get();

        // Стабильный порядок групп: свой личный → общие → делегированные.
        $sorted = $all->sortBy(fn (Mailbox $m) => match ($this->kindOf($m, $user, $delegatedOwnerIds)) {
            'personal' => 0,
            'shared' => 1,
            default => 2,
        })->values();

        return $this->cache[$key] = $sorted;
    }

    /** ID доступных ящиков — для scope-фильтра запроса писем. @return array<int, int> */
    public function mailboxIdsFor(User $user): array
    {
        return $this->mailboxesFor($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Тип доступа к ящику для UI-группировки: personal | shared | delegated.
     */
    public function kindOf(Mailbox $mailbox, User $user, ?array $delegatedOwnerIds = null): string
    {
        if ($mailbox->type === MailboxType::Shared) {
            return 'shared';
        }
        if ((int) $mailbox->owner_user_id === (int) $user->id) {
            return 'personal';
        }
        $delegatedOwnerIds ??= $this->delegatedOwnerIds($user);
        if (in_array((int) $mailbox->owner_user_id, $delegatedOwnerIds, true)) {
            return 'delegated';
        }

        // Личный ящик другого пользователя (привилегированный обзор
        // админа/директората) — показываем как personal (с именем владельца).
        return 'personal';
    }

    /** Роли с обзором всех ящиков (переключалка по всем): админ + директорат. */
    public function hasFullMailboxAccess(User $user): bool
    {
        return $user->hasAnyRole([Role::Admin->value, Role::Director->value]);
    }

    /** Есть ли у пользователя доступ к конкретному ящику. */
    public function canAccessMailbox(User $user, int $mailboxId): bool
    {
        return in_array($mailboxId, $this->mailboxIdsFor($user), true);
    }

    /** Ящик по умолчанию (первый личный, иначе первый доступный). */
    public function defaultMailboxId(User $user): ?int
    {
        $boxes = $this->mailboxesFor($user);
        $personal = $boxes->firstWhere('owner_user_id', $user->id);

        return (int) ($personal?->id ?? $boxes->first()?->id ?? 0) ?: null;
    }

    /**
     * ID менеджеров, чьи заявки делегированы этому пользователю (активно).
     *
     * @return array<int, int>
     */
    private function delegatedOwnerIds(User $user): array
    {
        // Только пользователи с обработчицкой ролью могут держать делегированные
        // ящики (директор/секретарь — не месте разбора личной почты).
        if (! $user->hasAnyRole([...Role::requestHandlerRoles(), Role::Admin->value])) {
            return [];
        }

        return RequestDelegation::query()
            ->active()
            ->where('acting_user_id', $user->id)
            ->distinct()
            ->pluck('original_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
