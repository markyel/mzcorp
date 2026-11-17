<?php

namespace App\Livewire\CalendarPoll;

use App\Jobs\CalendarPoll\SendCalendarPollInviteJob;
use App\Models\CalendarPoll;
use App\Models\CalendarPollPhoto;
use App\Models\CalendarPollVoter;
use App\Services\CalendarPoll\CalendarPollService;
use App\Services\CalendarPoll\VoterAccess;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Опрос «Фото для календаря» — организатору (РОП, директорат, админ): опрос, фото, участники, рассылка
 * личных ссылок, итоги. Логика — CalendarPollService; рассылка — очередью, по письму на задачу.
 */
class Manage extends Component
{
    use WithFileUploads;

    #[Url(as: 'poll', except: null)]
    public ?int $pollId = null;

    #[Url(as: 'tab', except: 'photos')]
    public string $tab = 'photos';

    public string $title = '';

    public string $description = '';

    public string $closesAt = '';

    public int $picks = 6;

    public int $winners = 12;

    /** Фото грузятся по одному ($wire.upload в цикле): пачкой упирались бы в 20 файлов / 50 МБ на запрос. */
    public $one = null;

    public string $votersText = '';

    public ?string $note = null;

    /** Ссылка, выданная вручную (для мессенджера): показываем один раз. */
    public ?array $issuedLink = null;

    public function mount(): void
    {
        abort_unless(VoterAccess::isOrganizer(auth()->user()), 403);
        $this->pollId ??= CalendarPoll::query()->latest('id')->value('id');
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $poll = $this->poll;
        $this->title = $poll?->title ?? 'Фото для календаря '.(now()->year + 1);
        $this->description = (string) ($poll?->description ?? '');
        $this->closesAt = $poll?->closes_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') ?? '';
        $this->picks = $poll?->picks ?? (int) config('services.calendar_poll.picks', 6);
        $this->winners = $poll?->winners ?? (int) config('services.calendar_poll.winners', 12);
    }

    #[Computed]
    public function poll(): ?CalendarPoll
    {
        return $this->pollId ? CalendarPoll::query()->find($this->pollId) : null;
    }

    #[Computed]
    public function polls()
    {
        return CalendarPoll::query()->latest('id')->get(['id', 'title', 'status']);
    }

    public function selectPoll(int $id): void
    {
        $this->pollId = $id;
        $this->reset(['note', 'issuedLink', 'one']);
        unset($this->poll);
        $this->fillForm();
    }

    public function newPoll(): void
    {
        $this->pollId = null;
        unset($this->poll);
        $this->fillForm();
        $this->tab = 'settings';
    }

    public function saveSettings(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'closesAt' => ['nullable', 'date'],
            'picks' => ['required', 'integer', 'min:1', 'max:20'],
            'winners' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['title' => 'название', 'closesAt' => 'срок', 'picks' => 'сколько выбирает каждый', 'winners' => 'сколько берём в итог']);
        $poll = $this->poll ?? new CalendarPoll(['status' => CalendarPoll::STATUS_DRAFT, 'created_by_user_id' => auth()->id()]);
        $hasVotes = $poll->exists && CalendarPollVoter::query()->where('calendar_poll_id', $poll->id)->whereNotNull('voted_at')->exists();
        if ($hasVotes && $poll->picks !== $this->picks) {
            $this->addError('picks', 'Уже есть голоса — число фото в выборе менять нельзя.');

            return;
        }
        $poll->fill([
            'title' => $this->title,
            'description' => $this->description ?: null,
            'closes_at' => $this->closesAt !== '' ? Carbon::parse($this->closesAt, config('app.timezone')) : null,
            'picks' => $this->picks,
            'winners' => $this->winners,
        ])->save();
        $this->pollId = $poll->id;
        unset($this->poll, $this->polls);
        $this->note = 'Сохранено.';
    }

    public function setStatus(string $status): void
    {
        $poll = $this->poll;
        if ($poll === null || ! in_array($status, [CalendarPoll::STATUS_DRAFT, CalendarPoll::STATUS_OPEN, CalendarPoll::STATUS_CLOSED], true)) {
            return;
        }
        if ($status === CalendarPoll::STATUS_OPEN && $poll->photos()->count() < $poll->picks) {
            $this->note = 'Фото меньше, чем нужно выбрать каждому ('.$poll->picks.') — сначала загрузите фото.';

            return;
        }
        $poll->forceFill(['status' => $status])->save();
        unset($this->poll, $this->polls);
        $this->note = match ($status) {
            CalendarPoll::STATUS_OPEN => 'Голосование открыто.',
            CalendarPoll::STATUS_CLOSED => 'Голосование завершено — выбор больше не принимается.',
            default => 'Опрос снова черновик — ссылки пока не пускают.',
        };
    }

    // ───────── Фото ─────────

    public function updatedOne(CalendarPollService $polls): void
    {
        $this->validate(['one' => ['required', 'image', 'max:29696']], ['one.image' => 'Нужны фото (JPG, PNG, WebP).', 'one.max' => 'Фото больше 29 МБ.']);
        if ($this->poll !== null) {
            $polls->addPhoto($this->poll, $this->one);
        }
        $this->one = null;
    }
    public function deletePhoto(int $id, CalendarPollService $polls): void
    {
        $photo = CalendarPollPhoto::query()->where('calendar_poll_id', $this->pollId)->findOrFail($id);
        $polls->deletePhoto($photo);
        $this->note = 'Фото удалено.';
    }

    // ───────── Участники ─────────

    public function addVoters(CalendarPollService $polls): void
    {
        $poll = $this->poll;
        if ($poll === null || trim($this->votersText) === '') {
            return;
        }
        $r = $polls->addVoters($poll, $this->votersText);
        $this->votersText = $r['invalid'] !== [] ? implode("\n", $r['invalid']) : '';
        $this->note = 'Добавлено: '.$r['added'].($r['existing'] ? ', уже были: '.$r['existing'] : '')
            .($r['duplicates'] ? ', повторы в списке: '.count($r['duplicates']) : '')
            .($r['invalid'] ? '. Без адреса — '.count($r['invalid']).' строк, оставлены в поле.' : '.');
    }

    public function removeVoter(int $id): void
    {
        CalendarPollVoter::query()->where('calendar_poll_id', $this->pollId)->whereKey($id)->delete();
        $this->note = 'Участник удалён вместе с его выбором.';
    }

    /** Рассылка: «new» — кто ещё не приглашён, «remind» — приглашён, но не выбрал (новая ссылка). */
    public function sendInvites(string $who): void
    {
        $poll = $this->poll;
        if ($poll === null || $poll->status !== CalendarPoll::STATUS_OPEN) {
            $this->note = 'Сначала откройте голосование — иначе ссылки не пустят.';

            return;
        }
        $q = CalendarPollVoter::query()->where('calendar_poll_id', $poll->id);
        $who === 'remind' ? $q->whereNotNull('invited_at')->whereNull('voted_at') : $q->whereNull('invited_at');
        $ids = $q->pluck('id');
        foreach ($ids as $id) {
            SendCalendarPollInviteJob::dispatch((int) $id);
        }
        $this->note = $ids->isEmpty() ? 'Некому отправлять.' : 'Письма ставятся в очередь: '.$ids->count().'. Статусы обновятся за пару минут.';
    }

    public function inviteOne(int $id): void
    {
        $voter = CalendarPollVoter::query()->where('calendar_poll_id', $this->pollId)->findOrFail($id);
        SendCalendarPollInviteJob::dispatch($voter->id);
        $this->note = 'Письмо для '.$voter->name.' в очереди.';
    }

    /** Ссылку — организатору (переслать в мессенджер); прежняя ссылка участника перестаёт работать. */
    public function issueLink(int $id, CalendarPollService $polls): void
    {
        $voter = CalendarPollVoter::query()->where('calendar_poll_id', $this->pollId)->findOrFail($id);
        $this->issuedLink = ['name' => $voter->name, 'url' => $polls->issueLink($voter)];
    }

    public function render(CalendarPollService $polls)
    {
        $poll = $this->poll;
        $photos = $poll ? $poll->photos()->withCount('votes')->get() : collect();
        $voters = $poll ? CalendarPollVoter::query()->where('calendar_poll_id', $poll->id)->orderBy('name')->get() : collect();

        return view('livewire.calendar-poll.manage', [
            'poll' => $poll,
            'photos' => $photos,
            'voters' => $voters,
            'ranking' => $poll && $this->tab === 'results' ? $polls->ranking($poll) : collect(),
            'processing' => $photos->contains(fn ($p) => ! $p->isReady() && $p->error === null),
        ]);
    }
}
