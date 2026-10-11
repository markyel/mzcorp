<?php

namespace App\Jobs\Mail;

use App\Enums\DraftSendStatus;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mail\OutboundReplyHooks;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Mail\OutgoingSendRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Повторная отправка черновика из очереди (OutgoingSendRetryService) —
 * письмо автора, которое не ушло, потому что SMTP был недоступен.
 *
 * Свои повторы job не делает (tries=1): расписание попыток живёт на самом
 * черновике (send_next_attempt_at), job ставит mail:send-queued.
 *
 * После успешной отправки — те же post-send хуки, что у композеров
 * (Mail\Composer, Requests\Mail\ComposeForm): маркеры уточнений/КП →
 * статусы заявки, иначе детект приложенных документов. Актор — автор черновика.
 */
class SendQueuedDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;
    public int $uniqueFor = 600;

    public function __construct(public readonly int $draftId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->draftId;
    }

    public function handle(OutgoingMailSender $sender, OutgoingSendRetryService $retry, OutboundReplyHooks $hooks): void
    {
        $draft = EmailMessage::find($this->draftId);
        // Отправлен вручную, удалён, снят с очереди правкой — делать нечего.
        if ($draft === null || ! $draft->is_draft || $draft->send_status !== DraftSendStatus::Queued) {
            return;
        }
        if ($draft->send_next_attempt_at !== null && $draft->send_next_attempt_at->isFuture()) {
            return;
        }

        // Основной SMTP не ответил несколько раз подряд — пробуем резервный
        // канал (Unisender Go / Postbox), если он настроен.
        $viaFallback = $retry->shouldUseFallback($draft);
        $result = $sender->sendDraft($draft->id, queueOnTransientFailure: true, viaFallback: $viaFallback);

        if ($result['success'] ?? false) {
            Log::info('SendQueuedDraftJob: sent from retry queue', [
                'draft_id' => $draft->id,
                'attempts' => $draft->send_attempts,
                'via_fallback' => $viaFallback,
            ]);
            $this->runPostSendHooks($hooks, $result['draft']);

            return;
        }

        $error = (string) ($result['error'] ?? 'unknown');
        if (($result['queued'] ?? false) || $error === 'send_in_progress' || $error === 'not_a_draft') {
            return;
        }

        $draft->refresh();

        // Токен OAuth не обновился — тот же сетевой сбой, только до SMTP.
        // Резервному каналу токен не нужен: следующая попытка, когда наберётся
        // after_attempts, пойдёт через него.
        if ($error === 'oauth_refresh_failed' && $draft->is_draft) {
            $retry->schedule($draft, 'Не удалось обновить OAuth-токен ящика');

            return;
        }

        // Резерв отказал по своей причине (ключ, домен не подтверждён) — это
        // не приговор письму: следующая попытка пойдёт через SMTP ящика.
        if ($viaFallback && $error === 'smtp_send_failed' && $draft->is_draft && $draft->send_status === DraftSendStatus::Queued) {
            $retry->schedule($draft, 'Резервный канал: '.($result['error_message'] ?? $error));

            return;
        }

        // Не временная ошибка (авторизация, ящик отключён, отказ 5xx) или
        // попытки исчерпаны — дальше повторять бессмысленно.
        if ($draft->is_draft && $draft->send_status !== DraftSendStatus::Failed) {
            $retry->markFailed($draft, (string) ($result['error_message'] ?? $error));
        }
    }

    private function runPostSendHooks(OutboundReplyHooks $hooks, EmailMessage $sent): void
    {
        if (! $sent->related_request_id) {
            return;
        }
        $author = $sent->draft_author_user_id ? User::find($sent->draft_author_user_id) : null;
        try {
            if ($author === null || ! $hooks->applyPostSendHooks($sent, $author)) {
                $hooks->detectOutboundDocuments($sent);
            }
        } catch (\Throwable $e) {
            Log::warning('SendQueuedDraftJob: post-send hooks failed (non-fatal)', [
                'email_message_id' => $sent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
