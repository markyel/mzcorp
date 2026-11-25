<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\RouterAiLowBalanceNotification;
use App\Services\AI\RouterAiGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Остаток на счёте RouterAI: ниже порога (services.routerai.balance_alert_rub) — уведомление админам,
 * не чаще раза в 12 часов. По расписанию раз в час.
 *
 *   php artisan llm:routerai-balance
 *   php artisan llm:routerai-balance --dry-run   — только показать остаток
 */
class LlmRouterAiBalanceCommand extends Command
{
    protected $signature = 'llm:routerai-balance {--dry-run : показать остаток, не уведомлять}';

    protected $description = 'RouterAI: остаток на счёте, предупреждение админам при низком балансе';

    private const NOTIFIED_KEY = 'routerai:low-balance:notified';

    private const NOTIFY_EVERY_HOURS = 12;

    public function handle(RouterAiGateway $router): int
    {
        if (! $router->enabled()) {
            $this->line('RouterAI выключен.');

            return self::SUCCESS;
        }
        $credits = $router->credits();
        if ($credits === null) {
            Log::warning('RouterAI balance: /credits did not answer');
            $this->warn('RouterAI не ответил на запрос баланса.');

            return self::SUCCESS;
        }
        $threshold = (float) config('services.routerai.balance_alert_rub', 1500);
        $this->line(sprintf('Остаток RouterAI: %.2f ₽ (порог %.0f ₽)', $credits, $threshold));

        if ($credits >= $threshold || $this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! Cache::add(self::NOTIFIED_KEY, now()->toIso8601String(), now()->addHours(self::NOTIFY_EVERY_HOURS))) {
            return self::SUCCESS;
        }

        Log::warning('RouterAI balance low', ['credits' => $credits, 'threshold' => $threshold]);
        foreach (User::role(Role::Admin->value)->get() as $admin) {
            try {
                $admin->notify(new RouterAiLowBalanceNotification($credits, $threshold));
            } catch (\Throwable $e) {
                Log::error('RouterAI balance: notify failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info('Админы уведомлены.');

        return self::SUCCESS;
    }
}
