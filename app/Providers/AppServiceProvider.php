<?php

namespace App\Providers;

use App\Models\RequestItem;
use App\Observers\RequestItemObserver;
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
    }
}
