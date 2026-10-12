<?php

namespace App\Jobs\Bot;

use App\Models\RequestStateChange;
use App\Services\Bot\BotRequestNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Уведомление клиенту бота о переходе статуса его заявки (см. RequestStateChangeObserver). */
class NotifyBotClientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Повтор отправил бы клиенту то же сообщение второй раз — одна попытка.
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $stateChangeId)
    {
    }

    public function handle(BotRequestNotifier $notifier): void
    {
        $change = RequestStateChange::find($this->stateChangeId);
        if ($change !== null) {
            $notifier->notify($change);
        }
    }
}
