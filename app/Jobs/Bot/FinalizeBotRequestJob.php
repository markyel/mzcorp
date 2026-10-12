<?php

namespace App\Jobs\Bot;

use App\Models\Request;
use App\Services\Bot\BotRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Заявка из бота после ответа клиенту: разбор текста, фото и файлов в
 * позиции, распределение, письмо «заявка принята» (BotRequestService::finalize).
 * Повтор не задвоит: разобранные позиции, назначение и письмо проверяются.
 */
class FinalizeBotRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $requestId)
    {
    }

    public function backoff(): array
    {
        return [60];
    }

    public function handle(BotRequestService $service): void
    {
        $request = Request::find($this->requestId);
        if ($request === null) {
            return;
        }
        // Очередь на проде переотдаёт задачу через 90 с (retry_after), а
        // распознавание двадцати фото идёт дольше — второй воркер не должен
        // разбирать ту же заявку параллельно (задвоил бы позиции).
        $lock = Cache::lock('bot:finalize:'.$request->id, $this->timeout);
        if (! $lock->get()) {
            return;
        }
        try {
            $service->finalize($request);
        } finally {
            $lock->release();
        }
    }
}
