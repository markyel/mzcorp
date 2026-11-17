<?php

namespace App\Jobs\CalendarPoll;

use App\Models\CalendarPollPhoto;
use App\Services\CalendarPoll\CalendarPollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Копии фото опроса «Фото для календаря» (миниатюра, просмотр) — вне запроса: снимки по 10–20 МБ, десятками. */
class MakeCalendarPollPhotoVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $photoId)
    {
    }

    public function handle(CalendarPollService $service): void
    {
        $photo = CalendarPollPhoto::query()->find($this->photoId);
        if ($photo !== null && ! $photo->isReady()) {
            $service->makeVariants($photo);
        }
    }
}
