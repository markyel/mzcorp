<?php

namespace App\Services\Mail;

use App\Enums\DraftSendStatus;
use App\Models\EmailMessage;
use App\Models\User;
use App\Notifications\OutgoingSendFailedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Очередь повторной отправки черновика, когда SMTP недоступен.
 *
 * Повод — 2026-10-11: smtp.yandex.ru не отвечал полдня, письма менеджеров
 * молча оставались черновиками, а о сбое говорил только тост в момент
 * нажатия «Отправить».
 *
 * Повторяем ТОЛЬКО ошибки, при которых письмо гарантированно не ушло:
 * соединение не установилось (сеть, TLS) или сервер временно отказал кодом
 * 4xx. Обрыв/таймаут посреди сессии не повторяем: сервер мог уже принять
 * письмо, и повтор отправит клиенту дубль — такой сбой, как и раньше,
 * показывается автору сразу.
 *
 * Сам SMTP-вызов — в OutgoingMailSender::sendDraft(queueOnTransientFailure);
 * повторы гонит SendQueuedDraftJob, их раз в минуту ставит mail:send-queued.
 */
class OutgoingSendRetryService
{
    /** SMTP-коды временного отказа: письмо не принято, можно повторить. */
    private const TRANSIENT_SMTP_CODES = [421, 450, 451, 452, 454];

    /** Обрывы ДО начала сессии — письмо точно не ушло. */
    private const TRANSIENT_CONNECT_PATTERNS = [
        'Connection could not be established',
        'Unable to connect with STARTTLS',
    ];

    public function isTransient(\Throwable $e): bool
    {
        if (! $e instanceof TransportExceptionInterface) {
            return false;
        }
        if ($e instanceof UnexpectedResponseException) {
            return in_array((int) $e->getCode(), self::TRANSIENT_SMTP_CODES, true);
        }
        foreach (self::TRANSIENT_CONNECT_PATTERNS as $pattern) {
            if (str_contains($e->getMessage(), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Засчитать неудачную попытку и поставить следующую. Если попытки
     * исчерпаны — «Не отправлено» + уведомление автору, вернёт null.
     */
    public function schedule(EmailMessage $draft, string $error): ?CarbonInterface
    {
        $attempts = (int) $draft->send_attempts + 1;
        $max = max(1, (int) config('services.mail_outbound.retry_max_attempts', 12));

        if ($attempts >= $max) {
            $draft->send_attempts = $attempts;
            $this->markFailed($draft, $error);

            return null;
        }

        $next = now()->addMinutes($this->backoffMinutes($attempts));
        $draft->forceFill([
            'send_status' => DraftSendStatus::Queued,
            'send_attempts' => $attempts,
            'send_next_attempt_at' => $next,
            'send_last_error' => mb_substr($error, 0, 1000),
        ])->saveQuietly();

        Log::warning('OutgoingSendRetry: queued', [
            'draft_id' => $draft->id,
            'mailbox_id' => $draft->mailbox_id,
            'attempts' => $attempts,
            'next_attempt_at' => $next->toDateTimeString(),
        ]);

        return $next;
    }

    /** Письмо не ушло окончательно: черновик остаётся, автор узнаёт в колокольчике. */
    public function markFailed(EmailMessage $draft, string $error): void
    {
        $draft->forceFill([
            'send_status' => DraftSendStatus::Failed,
            'send_attempts' => max(1, (int) $draft->send_attempts),
            'send_next_attempt_at' => null,
            'send_last_error' => mb_substr($error, 0, 1000),
        ])->saveQuietly();

        Log::error('OutgoingSendRetry: gave up', [
            'draft_id' => $draft->id,
            'mailbox_id' => $draft->mailbox_id,
            'attempts' => $draft->send_attempts,
            'error' => $error,
        ]);

        $author = $draft->draft_author_user_id ? User::find($draft->draft_author_user_id) : null;
        if ($author === null) {
            return;
        }
        try {
            $author->notify(OutgoingSendFailedNotification::from($draft));
        } catch (\Throwable $e) {
            Log::warning('OutgoingSendRetry: notify failed (non-fatal)', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Отправить при следующем проходе очереди («Отправить сейчас» / «ещё раз»). */
    public function requeueNow(EmailMessage $draft): void
    {
        $draft->forceFill([
            'send_status' => DraftSendStatus::Queued,
            'send_next_attempt_at' => now(),
            // «Отправить ещё раз» после исчерпанных попыток — новая серия.
            'send_attempts' => $draft->send_status === DraftSendStatus::Failed ? 0 : $draft->send_attempts,
        ])->saveQuietly();
    }

    /** Снять с очереди — снова обычный черновик. */
    public function reset(EmailMessage $draft): void
    {
        if ($draft->send_status === null && $draft->send_attempts === null) {
            return;
        }
        $draft->forceFill([
            'send_status' => null,
            'send_attempts' => null,
            'send_next_attempt_at' => null,
            'send_last_error' => null,
        ])->saveQuietly();
    }

    /** @return Collection<int, int> id черновиков, чья попытка наступила */
    public function dueDraftIds(int $limit = 50): Collection
    {
        return EmailMessage::query()
            ->where('is_draft', true)
            ->where('send_status', DraftSendStatus::Queued->value)
            ->where('send_next_attempt_at', '<=', now())
            ->orderBy('send_next_attempt_at')
            ->limit($limit)
            ->pluck('id');
    }

    public function fallbackEnabled(): bool
    {
        return trim((string) config('services.mail_outbound.fallback.dsn')) !== '';
    }

    /**
     * Следующую попытку — через резервный канал: SMTP ящика уже не ответил
     * fallback.after_attempts раз подряд. Дальше каналы чередуются (резерв,
     * ящик, резерв…): письмо уйдёт, какой бы из них ни поднялся первым, и
     * сломанная настройка резерва не держит письмо, когда Яндекс ожил.
     */
    public function shouldUseFallback(EmailMessage $draft): bool
    {
        if (! $this->fallbackEnabled()) {
            return false;
        }
        $after = max(1, (int) config('services.mail_outbound.fallback.after_attempts', 3));
        $attempts = (int) $draft->send_attempts;

        return $attempts >= $after && ($attempts - $after) % 2 === 0;
    }

    /** Что сказать автору, когда письмо ушло в очередь (тост / флеш). */
    public static function queuedMessage(?CarbonInterface $next): string
    {
        $when = $next ? ' (следующая попытка в '.$next->copy()->setTimezone(config('app.timezone'))->format('H:i').')' : '';

        return 'Почтовый сервер не отвечает — письмо в очереди, отправим автоматически'.$when.'. '
            .'Если откроете черновик для правки, он снимется с очереди.';
    }

    /** Пауза перед попыткой, следующей за $attempt-й неудачной. */
    public function backoffMinutes(int $attempt): int
    {
        $steps = config('services.mail_outbound.retry_backoff_minutes') ?: [1, 5, 15, 30, 60];
        $idx = min($attempt - 1, count($steps) - 1);

        return max(1, (int) $steps[$idx]);
    }
}
