<?php

namespace App\Jobs\CalendarPoll;

use App\Models\CalendarPollVoter;
use App\Services\CalendarPoll\CalendarPollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Приглашение в опрос «Фото для календаря» — по письму на задачу: рассылка на десятки адресов не держит страницу. */
class SendCalendarPollInviteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $voterId)
    {
    }

    public function handle(CalendarPollService $service): void
    {
        $voter = CalendarPollVoter::query()->with('poll')->find($this->voterId);
        if ($voter !== null && ! $service->invite($voter)) {
            Log::warning('CalendarPoll: invite not sent', ['voter_id' => $voter->id, 'email' => $voter->email]);
        }
    }
}
