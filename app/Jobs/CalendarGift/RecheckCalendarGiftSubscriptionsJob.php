<?php

namespace App\Jobs\CalendarGift;

use App\Models\CalendarGiftRecipient;
use App\Services\CalendarGift\ChannelSubscriptionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Перед отправкой календарей — подписаны ли ещё получатели (кто-то записался и отписался). Не проверили (сеть) —
 * прежняя отметка остаётся. Отменённых не трогаем.
 */
class RecheckCalendarGiftSubscriptionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $campaign) {}

    public function uniqueId(): string
    {
        return $this->campaign;
    }

    public function handle(ChannelSubscriptionChecker $checker): void
    {
        CalendarGiftRecipient::query()->where('campaign', $this->campaign)
            ->where('status', '!=', CalendarGiftRecipient::STATUS_CANCELLED)
            ->orderBy('id')->each(function (CalendarGiftRecipient $r) use ($checker) {
                $subscribed = $checker->isSubscribed($r->channel, (string) $r->external_user_id);
                if ($subscribed !== null) {
                    $r->forceFill(['subscribed' => $subscribed, 'subscription_checked_at' => now()])->save();
                }
                usleep(100_000); // площадки ограничивают частоту запросов
            });
    }
}
