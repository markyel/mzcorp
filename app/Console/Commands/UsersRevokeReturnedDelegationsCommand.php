<?php

namespace App\Console\Commands;

use App\Enums\Role as RoleEnum;
use App\Models\RequestDelegation;
use App\Models\User;
use App\Services\Request\ManagerUnavailabilityService;
use Illuminate\Console\Command;

/**
 * Снятие делегаций у менеджеров, ВЕРНУВШИХСЯ из отсутствия ПО ВРЕМЕНИ.
 *
 * Проблема: закрытие делегаций живёт только в
 * ManagerUnavailabilityService::markAvailable() — ручной вызов («сделать
 * доступным»). Но если отсутствие задано датой (`unavailable_until`), по её
 * истечении менеджер становится доступным АВТОМАТИЧЕСКИ (scope available()),
 * markAvailable никто не зовёт → делегации коллегам остаются висеть, заявки
 * вернувшегося менеджера продолжают показываться acting'ам (кейс Румянцев:
 * unavailable_until прошёл, а 194 делегации активны).
 *
 * Эта команда закрывает такие «зависшие» делегации: для каждого менеджера с
 * `unavailable_until` в прошлом и живыми active-делегациями (original_user)
 * зовёт markAvailable (чистит unavailable_* + закрывает все его делегации).
 *
 * Cron: hourly (симметрично users:apply-planned-unavailability).
 *
 * Usage:
 *   php artisan users:revoke-returned-delegations
 *   php artisan users:revoke-returned-delegations --dry-run
 */
class UsersRevokeReturnedDelegationsCommand extends Command
{
    protected $signature = 'users:revoke-returned-delegations
        {--dry-run : Показать кого затронули бы, без изменений}';

    protected $description = 'Снять делегации у менеджеров, вернувшихся из отсутствия (unavailable_until прошёл).';

    public function handle(ManagerUnavailabilityService $svc): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Вернувшиеся по времени: unavailable_until в прошлом, но с висящими
        // активными делегациями. (Ещё отсутствующие — until > now — не трогаем;
        // будущие планы — тоже.)
        $users = User::role(RoleEnum::requestHandlerRoles())
            ->whereNotNull('unavailable_until')
            ->where('unavailable_until', '<', now())
            ->get()
            ->filter(fn (User $u) => RequestDelegation::query()
                ->where('original_user_id', $u->id)
                ->whereNull('ended_at')
                ->exists());

        if ($users->isEmpty()) {
            $this->info('Нет вернувшихся менеджеров с зависшими делегациями.');

            return self::SUCCESS;
        }

        $totalClosed = 0;
        $rows = [];
        foreach ($users as $u) {
            $activeCount = RequestDelegation::query()
                ->where('original_user_id', $u->id)
                ->whereNull('ended_at')
                ->count();

            if ($dryRun) {
                $rows[] = [$u->id, $u->name, "dry-run: закрыли бы {$activeCount} делегаций"];
                continue;
            }

            $svc->markAvailable($u, null);
            $totalClosed += $activeCount;
            $rows[] = [$u->id, $u->name, "закрыто {$activeCount}, менеджер снова доступен"];
        }

        $this->table(['user_id', 'name', 'result'], $rows);
        $this->info(sprintf(
            '%s: %d менеджеров, %d делегаций закрыто.',
            $dryRun ? '--dry-run' : 'Готово',
            $users->count(),
            $totalClosed,
        ));

        return self::SUCCESS;
    }
}
