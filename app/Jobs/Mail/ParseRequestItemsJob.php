<?php

namespace App\Jobs\Mail;

use App\Models\EmailMessage;
use App\Services\Request\RequestItemPersister;
use App\Services\RequestItemParsingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.8d: автоматический content-driven парсинг позиций.
 *
 * Раньше `RequestItemParsingService` запускался только через CLI
 * (`requests:parse-items`) — каждая новая входящая заявка приходила в пул
 * с `Позиции 0`, и оператору приходилось вручную дёргать команду.
 *
 * Этот job триггерится из `IncomingMailProcessor::processIfRequest`
 * сразу после создания Request: парсит тело письма + вложения
 * (PDF / XLSX / DOCX / Vision OCR на изображениях), складывает позиции
 * через `RequestItemPersister` (он идемпотентен — повторные запуски
 * не дублируют).
 *
 * Стоимость: каждый прогон тратит OpenAI-токены (gpt-4.1 + gpt-4o Vision).
 * Идёт асинхронно через очередь, чтобы IMAP sync не блокировался на
 * 5–30 секунд парсинга на письмо.
 *
 * ShouldBeUnique: за окно 5 минут не больше одного job-а на email_message_id —
 * страховка от двойного dispatch'а из разных мест pipeline.
 */
class ParseRequestItemsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly int $emailMessageId,
        public readonly bool $force = false,
    ) {
    }

    public function uniqueId(): string
    {
        return sprintf('parse-items:%d', $this->emailMessageId);
    }

    public function uniqueFor(): int
    {
        return 5 * 60;
    }

    public function handle(
        RequestItemParsingService $parser,
        RequestItemPersister $persister,
    ): void {
        $message = EmailMessage::find($this->emailMessageId);
        if (! $message) {
            Log::info('ParseRequestItemsJob: message missing — skip', [
                'email_message_id' => $this->emailMessageId,
            ]);

            return;
        }

        // Идемпотентность: если у привязанного Request уже есть items
        // и не задан force — пропускаем (повторный dispatch не вредит).
        if (! $this->force && $message->related_request_id) {
            $alreadyHasItems = \App\Models\RequestItem::query()
                ->where('request_id', $message->related_request_id)
                ->exists();
            if ($alreadyHasItems) {
                return;
            }
        }

        try {
            $items = $parser->parseItemsFromInboundMessage($message);
        } catch (\Throwable $e) {
            Log::error('ParseRequestItemsJob: parser failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return; // не валим pipeline — Request уже создан, оператор увидит «Позиции 0»
        }

        if (empty($items)) {
            Log::info('ParseRequestItemsJob: empty items', [
                'email_message_id' => $message->id,
                'request_id' => $message->related_request_id,
            ]);

            return;
        }

        try {
            $result = $persister->persist($message, $items);
            Log::info('ParseRequestItemsJob: items persisted', [
                'email_message_id' => $message->id,
                'request_id' => $result['request']?->id,
                'new' => $result['new'],
                'dup' => $result['dup'],
            ]);
        } catch (\Throwable $e) {
            Log::error('ParseRequestItemsJob: persist failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
