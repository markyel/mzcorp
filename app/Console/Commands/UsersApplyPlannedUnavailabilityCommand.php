<?php

namespace App\Console\Commands;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Services\Request\ManagerUnavailabilityService;
use Illuminate\Console\Command;

/**
 * Foundation Фаза 2 — apply planned unavailability.
 *
 * Для каждого user с ролью manager:
 *  - unavailable_from <= now() (период наступил)
 *  - unavailable_until > now() (период ещё не прошёл — иначе scope::available()
 *    уже вернул бы его в пул и недоступность фактически закрылась)
 *  - unavailable_auto_delegate = true (РОП заранее попросил открыть)
 *  - НЕТ active delegations (idempotent — повторный запуск не плодит)
 *
 * → dispatch delegateActiveRequests($user).
 *
 * Cron: hourly (см. routes/console.php). Часовая гранулярность нормальная
 * для дневных периодов отсутствия — отставание max 1 час от
 * unavailable_from.
 *
 * Usage:
 *   php artisan users:apply-planned-unavailability
 *   php artisan users:apply-planned-unavailability --dry-run
 */
class UsersApplyPlannedUnavailabilityCommand extends Command
{
    protected $signature = 'users:apply-planned-unavailability
        {--dry-run : Показать кого затронули бы, без изменений}';

    protected $description = 'Применить запланированные отсутствия — открыть заявки коллегам в момент начала.';

    public function handle(ManagerUnavailabilityService $svc): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $users = User::role(RoleEnum::requestHandlerRoles())
            ->whereNotNull('unavailable_from')
            ->where('unavailable_from', '<=', now())
            ->whereNotNull('unavailable_until')
            ->where('unavailable_until', '>', now())
            ->where('unavailable_auto_delegate', true)
            ->get();

        if ($users->isEmpty()) {
            $this->info('Нет пользователей с активным планом auto-delegate.');

            return self::SUCCESS;
        }

        $totalDelegated = 0;
        $rows = [];
        foreach ($users as $u) {
            // Idempotent: если у пользователя уже есть active delegations
            // (например cron уже отработал час назад) — пропускаем.
            $hasActive = \App\Models\RequestDelegation::query()
                ->where('original_user_id', $u->id)
                ->whereNull('ended_at')
                ->exists();
            if ($hasActive) {
                $rows[] = [$u->id, $u->name, 'skip (уже есть active delegations)'];
                continue;
            }

            if ($dryRun) {
                $count = \App\Models\Request::query()
                    ->where('assigned_user_id', $u->id)
                    ->whereIn('status', collect(\App\Enums\RequestStatus::cases())
                        ->filter(fn ($s) => $s->isOpenForAssignment())
                        ->map(fn ($s) => $s->value)
                        ->all())
                    ->count();
                $rows[] = [$u->id, $u->name, "dry-run: открыли бы {$count} заявок"];
            } else {
                $stats = $svc->delegateActiveRequests($u, null);
                $totalDelegated += $stats['delegated'];
                $rows[] = [$u->id, $u->name, sprintf(
                    'открыто %d, пропущено %d',
                    $stats['delegated'],
                    $stats['skipped'],
                )];
            }
        }

        $this->table(['user_id', 'name', 'result'], $rows);
        $this->info(sprintf(
            '%s: %d пользователей, %d заявок открыто.',
            $dryRun ? '--dry-run' : 'Готово',
            $users->count(),
            $totalDelegated,
        ));

        return self::SUCCESS;
    }
}
