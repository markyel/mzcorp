<?php

namespace App\Livewire\CalendarPoll;

use App\Models\CalendarPoll;
use App\Models\CalendarPollVoter;
use App\Services\CalendarPoll\CalendarPollService;
use App\Services\CalendarPoll\VoterAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Голосование «Фото для календаря»: галерея, выбор picks фото по местам (порядок — в браузере, Alpine),
 * сохранение одним вызовом. Участник определяется заново при каждом действии (cookie устройства / аккаунт),
 * а не по свойству компонента — подменить его нельзя.
 */
class Vote extends Component
{
    #[Locked]
    public int $pollId;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(int $pollId): void
    {
        $this->pollId = $pollId;
    }

    private function voter(): ?CalendarPollVoter
    {
        return app(VoterAccess::class)->current(CalendarPoll::query()->findOrFail($this->pollId));
    }

    /** @param  list<int>  $ids  1-е место первым */
    public function save(array $ids, CalendarPollService $polls): void
    {
        $this->flash = $this->error = null;
        $voter = $this->voter();
        if ($voter === null) {
            $this->error = 'Не удалось определить участника — откройте ссылку из письма ещё раз.';

            return;
        }
        try {
            $polls->saveChoice($voter, $ids);
            $poll = $voter->poll;
            $this->flash = 'Выбор сохранён.'.($poll->closes_at ? ' Поменять можно до '.$poll->closes_at->timezone(config('app.timezone'))->format('d.m H:i').'.' : ' Поменять можно до конца голосования.');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $poll = CalendarPoll::query()->findOrFail($this->pollId);
        $voter = $this->voter();
        $photos = $poll->photos()->get()->filter->isReady()->values();
        $chosen = $voter
            ? $voter->votes()->orderBy('rank')->pluck('calendar_poll_photo_id')->map(fn ($v) => (int) $v)->filter(fn ($id) => $photos->contains('id', $id))->values()->all()
            : [];

        return view('livewire.calendar-poll.vote', [
            'poll' => $poll,
            'voter' => $voter,
            'items' => $photos->map(fn ($p, $i) => [
                'id' => $p->id,
                'thumb' => route('calendar-poll.photo', [$poll, $p, 'thumb']),
                'src' => route('calendar-poll.photo', [$poll, $p, 'preview']),
                'name' => 'Фото '.($i + 1),
            ])->values()->all(),
            'chosen' => $chosen,
        ]);
    }
}
