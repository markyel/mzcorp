<?php

namespace App\Http\Controllers;

use App\Models\CalendarPoll;
use App\Models\CalendarPollPhoto;
use App\Models\CalendarPollVoter;
use App\Services\CalendarPoll\CalendarPollService;
use App\Services\CalendarPoll\VoterAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Опрос «Фото для календаря» — страницы без входа в MyLift: вход по личной ссылке, страница голосования,
 * фото (только участникам и организаторам), новая ссылка на почту из списка.
 */
class CalendarPollController extends Controller
{
    public function __construct(
        private readonly CalendarPollService $polls,
        private readonly VoterAccess $access,
    ) {
    }

    /** Вход по личной ссылке: одноразовая — второй раз пускает только запомненное устройство. */
    public function enter(string $token)
    {
        $byToken = CalendarPollVoter::query()->with('poll')->where('token_hash', CalendarPollService::hash($token))->first();
        if ($byToken !== null && $this->access->current($byToken->poll)?->id === $byToken->id) {
            return redirect()->route('calendar-poll.show', $byToken->poll);
        }
        $res = $this->polls->enter($token);
        if ($res['reason'] === null) {
            $this->access->remember($res['voter']);

            return redirect()->route('calendar-poll.show', $res['voter']->poll);
        }

        return response()->view('calendar-poll.link', [
            'reason' => $res['reason'],
            'voter' => $res['voter'],
            'token' => $token,
        ], $res['reason'] === 'unknown' ? 404 : 200);
    }

    /** Новая ссылка на адрес участника — по старой ссылке (использована/истекла). Не чаще раза в 5 минут. */
    public function resend(string $token)
    {
        $voter = CalendarPollVoter::query()->with('poll')->where('token_hash', CalendarPollService::hash($token))->first();
        abort_if($voter === null, 404);

        return $this->sendNewLink($voter, $voter->poll);
    }

    /** «Пришлите ссылку»: адрес из списка опроса — придёт ссылка; ответ одинаковый, есть адрес в списке или нет. */
    public function requestLink(Request $request, CalendarPoll $poll)
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $voter = $email !== '' ? CalendarPollVoter::query()->where('calendar_poll_id', $poll->id)->where('email', $email)->first() : null;
        if ($voter !== null && $poll->status !== CalendarPoll::STATUS_DRAFT) {
            return $this->sendNewLink($voter, $poll);
        }

        return response()->view('calendar-poll.link', ['reason' => 'sent', 'voter' => null, 'email' => $email, 'poll' => $poll]);
    }

    private function sendNewLink(CalendarPollVoter $voter, CalendarPoll $poll)
    {
        $key = 'calendar-poll-link:'.$voter->id;
        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 300);
            $this->polls->invite($voter);
        }

        return response()->view('calendar-poll.link', ['reason' => 'sent', 'voter' => $voter, 'poll' => $poll]);
    }

    /** Страница голосования; без участника — «войдите по ссылке из письма». */
    public function show(CalendarPoll $poll)
    {
        $voter = $this->access->current($poll);
        if ($voter === null && ! VoterAccess::isOrganizer(auth()->user())) {
            return response()->view('calendar-poll.link', ['reason' => 'anonymous', 'voter' => null, 'poll' => $poll], 403);
        }
        if ($voter !== null) {
            $voter->forceFill(['last_seen_at' => now()])->save();
        }

        return view('calendar-poll.vote', ['poll' => $poll]);
    }

    /** Фото — участнику опроса или организатору; оригинал — только организатору. */
    public function photo(CalendarPoll $poll, CalendarPollPhoto $photo, string $size): Response
    {
        abort_unless($photo->calendar_poll_id === $poll->id, 404);
        $organizer = VoterAccess::isOrganizer(auth()->user());
        abort_unless($organizer || $this->access->current($poll) !== null, 403);
        $path = match ($size) {
            'thumb' => $photo->thumb_path,
            'preview' => $photo->preview_path,
            'original' => $organizer ? $photo->path : null,
            default => null,
        };
        abort_if($path === null || ! Storage::disk(CalendarPollService::DISK)->exists($path), 404);

        $response = Storage::disk(CalendarPollService::DISK)->response($path, $size === 'original' ? $photo->original_name : null);
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }
}
