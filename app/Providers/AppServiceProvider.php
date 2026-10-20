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
        // Перевод примечания к запросу поставщикам — один на рассылку (запрос / job), не на каждого поставщика.
        $this->app->scoped(\App\Services\Supplier\SupplierNoteTranslator::class);
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

        // Переход статуса заявки из бота — уведомление клиенту в мессенджер.
        \App\Models\RequestStateChange::observe(\App\Observers\RequestStateChangeObserver::class);
    }
}
