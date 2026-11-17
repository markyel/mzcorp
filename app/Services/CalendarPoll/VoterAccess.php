<?php

namespace App\Services\CalendarPoll;

use App\Models\CalendarPoll;
use App\Models\CalendarPollVoter;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;

/**
 * Кто смотрит страницу опроса: участник по cookie этого устройства (ставится при входе по ссылке, шифруется
 * Laravel) или сотрудник MyLift, чей адрес есть в списке опроса. Организаторы видят фото и итоги.
 */
class VoterAccess
{
    public const ORGANIZER_ROLES = ['head_of_sales', 'director', 'admin'];

    public static function cookieName(CalendarPoll $poll): string
    {
        return 'cpoll_'.$poll->id;
    }

    public function current(CalendarPoll $poll): ?CalendarPollVoter
    {
        $id = (int) request()->cookie(self::cookieName($poll));
        if ($id > 0 && ($voter = CalendarPollVoter::query()->where('calendar_poll_id', $poll->id)->find($id)) !== null) {
            return $voter;
        }
        $user = auth()->user();
        if ($user !== null) {
            return CalendarPollVoter::query()->where('calendar_poll_id', $poll->id)
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)]))
                ->first();
        }

        return null;
    }

    public function remember(CalendarPollVoter $voter): void
    {
        Cookie::queue(self::cookieName($voter->poll), (string) $voter->id, max(1, (int) config('services.calendar_poll.cookie_days', 30)) * 24 * 60);
    }

    public static function isOrganizer(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(self::ORGANIZER_ROLES);
    }

    /** m***@domain.ru — подсказка, куда ушла ссылка, без раскрытия адреса. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
