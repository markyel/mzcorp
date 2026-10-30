<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\RequestDelegation;
use App\Models\User;
use App\Services\Request\ManagerUnavailabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Foundation Фаза 2 — apply planned unavailability.
 *
 * Для каждого user с ролью manager:
 *  - unavailable_from <= now() (период наступил)
 *  - unavailable_until > now() (период ещё не прошёл — иначе scope::available()
 *    уже вернул бы его в пул и недоступность фактически закрылась)
 *  - unavailable_auto_delegate = true (РОП заранее попросил открыть)
 *    ИЛИ у него уже есть active delegations (делегирование выбрано)
 *  - у него есть открытые заявки БЕЗ active delegation
 *
 * → dispatch delegateActiveRequests($user) — он раздаёт только заявки без
 * active delegation, повторный запуск дублей не плодит.
 *
 * Дораздача: раньше менеджер с active delegations пропускался целиком, и
 * заявка, севшая на отпускника в обход назначения (дочерняя на счёт по
 * выигранной сделке до фикса 22.10), висела без коллеги весь отпуск
 * (M-2026-20107: 19.10 → 30.10, 34 из 35 заявок Курзаева были делегированы).
 * Теперь такие заявки догоняются в течение часа, каким бы путём ни пришли.
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

        // Период идёт (from пустой — отсутствие с момента отметки; from в прошлом) и
        // делегирование выбрано: флаг auto_delegate или уже открытые делегации.
        $users = User::role(RoleEnum::requestHandlerRoles())
            ->where(fn ($q) => $q->whereNull('unavailable_from')->orWhere('unavailable_from', '<=', now()))
            ->whereNotNull('unavailable_until')
            ->where('unavailable_until', '>', now())
            ->where(fn ($q) => $q->where('unavailable_auto_delegate', true)
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('request_delegations')
                    ->whereColumn('request_delegations.original_user_id', 'users.id')
                    ->whereNull('request_delegations.ended_at')))
            ->get();

        if ($users->isEmpty()) {
            $this->info('Нет отсутствующих менеджеров с делегированием.');

            return self::SUCCESS;
        }

        $openStatuses = collect(RequestStatus::cases())
            ->filter(fn ($s) => $s->isOpenForAssignment())
            ->map(fn ($s) => $s->value)
            ->all();

        $totalDelegated = 0;
        $rows = [];
        foreach ($users as $u) {
            // Открытые заявки без active delegation: в момент начала отсутствия — все,
            // потом — только севшие на него в обход назначения.
            $missing = Request::query()
                ->where('assigned_user_id', $u->id)
                ->whereIn('status', $openStatuses)
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('request_delegations')
                    ->whereColumn('request_delegations.request_id', 'requests.id')
                    ->whereNull('request_delegations.ended_at'))
                ->pluck('internal_code');
            if ($missing->isEmpty()) {
                $rows[] = [$u->id, $u->name, 'все открытые заявки уже делегированы'];
                continue;
            }

            $hasActive = RequestDelegation::query()
                ->where('original_user_id', $u->id)
                ->whereNull('ended_at')
                ->exists();
            if ($dryRun) {
                $rows[] = [$u->id, $u->name, sprintf('dry-run: %s бы %d: %s',
                    $hasActive ? 'дораздали' : 'открыли', $missing->count(), $missing->take(10)->implode(', '))];
            } else {
                $stats = $svc->delegateActiveRequests($u, null);
                $totalDelegated += $stats['delegated'];
                $rows[] = [$u->id, $u->name, sprintf(
                    '%s %d, пропущено %d',
                    $hasActive ? 'дораздано' : 'открыто',
                    $stats['delegated'],
                    $stats['skipped'],
                )];
                if ($hasActive && $stats['delegated'] > 0) {
                    Log::warning('users:apply-planned-unavailability: open requests of an absent manager were left without delegation', [
                        'user_id' => $u->id,
                        'requests' => $missing->all(),
                        'delegated' => $stats['delegated'],
                    ]);
                }
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
