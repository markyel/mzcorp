<?php

namespace App\Services\Request;

use App\Enums\EmailCategory;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Mail\IncomingMailProcessor;
use Illuminate\Support\Facades\Log;

/**
 * Разворачивание ответа клиента в ОТДЕЛЬНУЮ новую заявку.
 *
 * Кейс: клиент ответил в старом треде (по заявке с КП/счётом), но прислал
 * СОВЕРШЕННО НОВЫЙ запрос, не связанный с текущей сделкой. LLM-классификатор
 * (InboundIntentClassifier intent=new_request) это распознал — и вместо того,
 * чтобы добавлять позиции в старую заявку, мы создаём новую.
 *
 * Реализация: отвязываем письмо от старой заявки и переинтерпретируем его как
 * client_request, после чего гоняем штатный `IncomingMailProcessor` — он
 * создаёт Request, парсит позиции и через персистер авто-назначает менеджера
 * (sticky direct_mailbox / round-robin), доставляет в ящик и т.д. Старая заявка
 * не меняется. Ошибка LLM обратима: новую заявку можно слить назад (merge).
 */
class RequestExtensionService
{
    /**
     * @return Request|null  новая заявка (или null, если IncomingMailProcessor
     *                       решил, что заявку создавать не нужно).
     */
    public function spinOffNewRequest(EmailMessage $message, Request $source): ?Request
    {
        // Отвязываем от старой заявки и помечаем как новый клиентский запрос —
        // IncomingMailProcessor пропускает письма с related_request_id и
        // не-client_request категорией.
        $message->forceFill([
            'related_request_id' => null,
            'category' => EmailCategory::ClientRequest->value,
        ])->save();

        $new = app(IncomingMailProcessor::class)->processIfRequest($message->fresh());

        if ($new === null) {
            Log::warning('RequestExtensionService: spin-off produced no request', [
                'email_message_id' => $message->id,
                'source_request_id' => $source->id,
            ]);

            return null;
        }

        RequestStateChange::create([
            'request_id' => $new->id,
            'from_status' => null,
            'to_status' => $new->status->value,
            'by_user_id' => null,
            'event' => 'created_from_thread_reply',
            'comment' => sprintf(
                'Создана из ответа клиента в треде %s (LLM: отдельная новая заявка).',
                $source->internal_code,
            ),
            'payload' => [
                'source_request_id' => $source->id,
                'source_internal_code' => $source->internal_code,
                'source_email_message_id' => $message->id,
            ],
        ]);

        Log::info('RequestExtensionService: spun off new request from thread reply', [
            'email_message_id' => $message->id,
            'source_request_id' => $source->id,
            'new_request_id' => $new->id,
            'new_internal_code' => $new->internal_code,
        ]);

        return $new;
    }
}
