<?php

namespace App\Console\Commands;

use App\Jobs\Iqot\PollIqotSubmissionsJob;
use Illuminate\Console\Command;

/**
 * Опрос IQOT по submissions, которым пора (respects X-Next-Check-After),
 * или по конкретной записи. Крон — ежечасно. См. routes/console.php.
 *
 *   php artisan iqot:poll            # все, кому пора
 *   php artisan iqot:poll --id=42    # конкретная iqot_submissions.id
 */
class IqotPollCommand extends Command
{
    protected $signature = 'iqot:poll {--id= : iqot_submissions.id для точечного опроса}';

    protected $description = 'Poll IQOT for submissions awaiting updates and fold reports into positions';

    public function handle(): int
    {
        $id = $this->option('id');
        PollIqotSubmissionsJob::dispatchSync(is_null($id) ? null : (int) $id);
        $this->info($id ? "IQOT poll: targeted submission #{$id}" : 'IQOT poll: batch complete');

        return self::SUCCESS;
    }
}
