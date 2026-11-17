<?php

namespace App\Http\Controllers;

use App\Models\CalendarPoll;
use App\Services\CalendarPoll\CalendarPollService;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Опрос «Фото для календаря» — организатору: оригиналы победителей одним архивом, по местам. */
class CalendarPollAdminController extends Controller
{
    public function winners(CalendarPoll $poll, CalendarPollService $polls)
    {
        $top = $polls->ranking($poll)->filter(fn ($r) => $r['votes'] > 0)->take($poll->winners);
        abort_if($top->isEmpty(), 404, 'Пока никто не выбрал фото.');

        $tmp = tempnam(sys_get_temp_dir(), 'cpoll');
        $zip = new ZipArchive();
        abort_unless($zip->open($tmp, ZipArchive::OVERWRITE) === true, 500);
        $disk = Storage::disk(CalendarPollService::DISK);
        foreach ($top as $row) {
            $photo = $row['photo'];
            if ($disk->exists($photo->path)) {
                $name = sprintf('%02d_%d-баллов_%s', $row['place'], $row['points'], preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $photo->original_name));
                $zip->addFile($disk->path($photo->path), $name);
            }
        }
        $zip->close();

        return response()->download($tmp, 'calendar-'.$poll->id.'-top'.$top->count().'.zip')->deleteFileAfterSend();
    }
}
