<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\QueueStalledNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сторож очередей (database driver): если в очереди есть невзятая задача,
 * ждущая дольше порога, — warning в лог и уведомление админам (bell + mail),
 * не чаще notify_cooldown_minutes. Пороги по очередям — config('queue.watchdog').
 *
 * Зачем: 2026-09-08 очередь `default` (маршрутизация писем в папки менеджеров,
 * доставка, парсинг позиций) стояла 1.5 часа, пока воркеры выгребали
 * забитую `mail-sync`, — заметили только по жалобе заказчика.
 *
 *   php artisan queue:watchdog          — проверить и уведомить
 *   php artisan queue:watchdog --dry    — только показать метрики
 */
class QueueWatchdogCommand extends Command
{
    protected $signature = 'queue:watchdog {--dry : Только показать состояние, без уведомлений}';

    protected $description = 'Проверить, не встала ли очередь задач (возраст самой старой невзятой задачи по очередям)';

    private const CACHE_NOTIFIED = 'queue:watchdog:notified_at';

    public function handle(): int
    {
        /** @var array<string,int> $thresholds */
        $thresholds = (array) config('queue.watchdog.queues', []);
        if ($thresholds === []) {
            $this->warn('queue.watchdog.queues пуст — нечего проверять.');

            return self::SUCCESS;
        }

        $now = time();
        $rows = DB::table('jobs')
            ->whereNull('reserved_at')
            ->whereIn('queue', array_keys($thresholds))
            ->where('available_at', '<=', $now)
            ->groupBy('queue')
            ->selectRaw('queue, count(*) as c, min(available_at) as oldest')
            ->get()
            ->keyBy('queue');
        $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();

        $stalled = [];
        foreach ($thresholds as $queue => $minutes) {
            $r = $rows->get($queue);
            $age = $r ? (int) floor(($now - (int) $r->oldest) / 60) : 0;
            $count = $r ? (int) $r->c : 0;
            $this->line(sprintf('%-16s %5d задач, самая старая ждёт %3d мин (порог %d)', $queue, $count, $age, $minutes));
            if ($r && $age >= (int) $minutes) {
                $stalled[$queue] = ['count' => $count, 'oldest_minutes' => $age, 'threshold' => (int) $minutes];
            }
        }
        $this->line("в работе у воркеров: {$reserved}");

        if ($stalled === []) {
            return self::SUCCESS;
        }

        Log::warning('queue:watchdog: очередь встала', ['stalled' => $stalled, 'reserved' => $reserved]);
        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        $cooldown = max(5, (int) config('queue.watchdog.notify_cooldown_minutes', 60));
        if (! Cache::add(self::CACHE_NOTIFIED, now()->toIso8601String(), now()->addMinutes($cooldown))) {
            $this->info('Уведомление уже отправляли недавно — пропуск (cooldown).');

            return self::SUCCESS;
        }

        try {
            foreach (User::role(Role::Admin->value)->get() as $admin) {
                $admin->notify(new QueueStalledNotification($stalled, $reserved));
            }
            $this->info('Админы уведомлены.');
        } catch (\Throwable $e) {
            Log::error('queue:watchdog: notify failed', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
