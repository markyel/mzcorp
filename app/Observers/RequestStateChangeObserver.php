<?php

namespace App\Observers;

use App\Jobs\Bot\NotifyBotClientJob;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Bot\BotRequestNotifier;
use App\Enums\RequestStatus;

/**
 * Переход статуса заявки из бота — уведомление клиенту в мессенджер
 * (BotRequestNotifier). В очереди и после коммита: переход не должен ждать
 * мессенджер и падать из-за него.
 */
class RequestStateChangeObserver
{
    public function created(RequestStateChange $change): void
    {
        $to = RequestStatus::tryFrom((string) $change->to_status);
        if ($to === null || $change->from_status === $change->to_status) {
            return;
        }
        $request = Request::query()->select(['id', 'internal_code', 'client_email', 'messenger_chat_id'])->find($change->request_id);
        if ($request?->messenger_chat_id === null || BotRequestNotifier::text($request, $to) === null) {
            return;
        }

        NotifyBotClientJob::dispatch($change->id)->afterCommit();
    }
}
