<?php

namespace App\Providers;

use App\Models\RequestItem;
use App\Models\User;
use App\Observers\RequestItemObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Любое изменение позиции (created / updated / deleted) поднимает
        // requests.last_activity_at — Pool сортирует «свежие сверху».
        RequestItem::observe(RequestItemObserver::class);

        // При архивировании пользователя автоматически деактивируем его
        // personal-mailbox'ы — иначе sync продолжает тянуть туда письма,
        // а backfill-cron APPEND'ит копии в orphan-ящик. См. UserObserver.
        User::observe(UserObserver::class);
    }
}
