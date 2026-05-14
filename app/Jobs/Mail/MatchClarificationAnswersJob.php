<?php

namespace App\Jobs\Mail;

use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Services\Mail\ClarificationAnswerMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Foundation §6.2 Phase B/C — async LLM-сматчинг inbound клиентского
 * ответа с pending ClarificationBatch.
 *
 * Диспатчится из `MailRouter` после InboundReplyLinker если у linked
 * Request есть `clarification_batches` со status='sent'. Один job на
 * пару (inbound_message_id, batch_id) — повторный dispatch не плодит
 * через ShouldBeUnique key.
 *
 * Цена: ~$0.005 на запрос (gpt-4o-mini, ~3000 prompt tokens).
 */
class MatchClarificationAnswersJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int $emailMessageId,
        public readonly int $batchId,
    ) {
    }

    public function uniqueId(): string
    {
        return sprintf('clarif-match:%d:%d', $this->emailMessageId, $this->batchId);
    }

    public function uniqueFor(): int
    {
        return 10 * 60; // 10 минут — этого достаточно от dispatch до execute
    }

    public function handle(ClarificationAnswerMatcher $matcher): void
    {
        $inbound = EmailMessage::find($this->emailMessageId);
        $batch = ClarificationBatch::find($this->batchId);

        if (! $inbound || ! $batch) {
            Log::info('MatchClarificationAnswersJob: data missing — skip', [
                'email_message_id' => $this->emailMessageId,
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        // Sanity: batch уже answered — не запускаем (idempotency).
        if ($batch->status === ClarificationBatch::STATUS_ANSWERED) {
            return;
        }
        if ($batch->status !== ClarificationBatch::STATUS_SENT) {
            // drafted/cancelled — повторно не маршрутизируем
            return;
        }

        try {
            $matcher->match($inbound, $batch);
        } catch (\Throwable $e) {
            Log::error('MatchClarificationAnswersJob: matcher failed', [
                'email_message_id' => $this->emailMessageId,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
