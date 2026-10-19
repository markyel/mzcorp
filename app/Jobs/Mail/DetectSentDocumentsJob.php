<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Mail\MailRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Детект исходящего клиенту (КП / счёт / запрос уточнения / отказ) после отправки письма из
 * mzCorp — в фоне: письмо без файла разбирает ИИ-классификатор (1–3 с), кнопка «Отправить» его не
 * ждёт. Логика — OutboundDocumentDetectionService (идемпотентна).
 */
class DetectSentDocumentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $emailMessageId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->emailMessageId;
    }

    public function handle(MailRouter $router): void
    {
        $message = EmailMessage::query()->find($this->emailMessageId);
        $request = $message?->related_request_id ? Request::query()->find($message->related_request_id) : null;
        if ($message === null || $request === null) {
            return;
        }
        $router->runOutboundDocumentDetection($message, $request);
    }
}
