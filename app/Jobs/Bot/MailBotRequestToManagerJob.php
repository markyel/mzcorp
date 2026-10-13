<?php

namespace App\Jobs\Bot;

use App\Mail\BotRequestForManagerMail;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\ManagerInboxCopyService;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Письмо менеджеру о заявке из бота при назначении (AssignmentService,
 * ReassignService) — вместо копии письма клиента, которой у такой заявки нет.
 * Одному менеджеру по одной заявке — один раз.
 */
class MailBotRequestToManagerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $requestId, public readonly int $managerId)
    {
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SystemNotificationMailer $mailer, ManagerInboxCopyService $copies): void
    {
        $request = Request::query()->with(['items', 'organization:id,name'])->find($this->requestId);
        $manager = User::find($this->managerId);
        if ($request === null || $manager === null || trim((string) $manager->email) === ''
            || (int) $request->assigned_user_id !== (int) $manager->id) {
            return;
        }
        $key = 'bot:request-mail:'.$request->id.':'.$manager->id;
        if (Cache::has($key)) {
            return;
        }

        $mail = new BotRequestForManagerMail($request);
        $messageId = 'botreq-'.$request->id.'-'.$manager->id.'-'.now()->timestamp.'@mzcorp.ru';
        if (! $mailer->sendMailable((string) $manager->email, $mail, null, $messageId)) {
            Log::warning('MailBotRequestToManagerJob: not sent', ['request_id' => $request->id, 'manager_id' => $manager->id]);
            throw new \RuntimeException('bot request mail to manager not sent');
        }
        Cache::put($key, 1, now()->addDays(30));

        // Копия во «Входящих» mzCorp сразу — не ждём, пока Яндекс примет письмо.
        $copies->store($manager, $mail, $messageId, ['bot_request_notice' => true]);
    }
}
