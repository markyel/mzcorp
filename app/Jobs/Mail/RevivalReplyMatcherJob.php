<?php

namespace App\Jobs\Mail;

use App\Enums\RequestStatus;
use App\Models\ClientNotificationSent;
use App\Models\EmailMessage;
use App\Services\AI\OpenAIChatService;
use App\Services\Request\AttentionService;
use App\Services\Request\RequestStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Обработка ответа клиента на «оживляющее» письмо (ClientNotificationType::
 * RevivalOffer). Если клиент согласен получить актуальное КП — реанимируем
 * проигранную (closed_lost) заявку. Иначе ничего с заявкой не делаем.
 *
 * Идемпотентно: помечает ClientNotificationSent.responded_at — повторные
 * reply'и клиента LLM не дёргают. Диспатчится из MailRouter, когда inbound
 * reply привязался к заявке с неотвеченным RevivalOffer.
 */
class RevivalReplyMatcherJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $emailMessageId,
        public int $clientNotificationSentId,
    ) {
    }

    public function handle(
        OpenAIChatService $ai,
        RequestStateService $stateService,
        AttentionService $attention,
    ): void {
        $sent = ClientNotificationSent::find($this->clientNotificationSentId);
        if (! $sent || $sent->responded_at !== null) {
            return; // нет записи / уже обработали
        }
        $message = EmailMessage::find($this->emailMessageId);
        $request = $sent->request;
        if (! $message || ! $request) {
            return;
        }

        // Реанимация возможна только из closed_lost. Если заявку уже оживили
        // (вручную / иным сигналом) — просто фиксируем ответ и выходим.
        if ($request->status !== RequestStatus::ClosedLost) {
            $sent->forceFill(['responded_at' => now(), 'response_intent' => 'already_active'])->save();

            return;
        }

        // Классификация (может бросить при сбое OpenAI → job ретраит; responded_at
        // ещё не выставлен, так что повторная попытка корректна).
        $intent = $this->classify($ai, $message);

        $sent->forceFill(['responded_at' => now(), 'response_intent' => $intent])->save();

        if ($intent !== 'positive') {
            Log::info('RevivalReplyMatcherJob: non-positive reply — заявку не трогаем', [
                'request_id' => $request->id,
                'intent' => $intent,
                'email_message_id' => $message->id,
            ]);

            return;
        }

        try {
            $stateService->reanimate(
                request: $request,
                author: null, // system-actor
                sourceMessage: $message,
                reassessAssignee: true,
                event: 'revival_positive_reply',
                comment: 'Клиент согласился на актуальное КП после оживляющего письма — реанимация.',
            );
        } catch (\Throwable $e) {
            Log::warning('RevivalReplyMatcherJob: reanimate failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $attention->onClientReplied($request->fresh());
        } catch (\Throwable $e) {
            // non-fatal — реанимация уже произошла
        }

        Log::info('RevivalReplyMatcherJob: reanimated on positive revival reply', [
            'request_id' => $request->id,
            'email_message_id' => $message->id,
        ]);
    }

    /**
     * positive | negative | unclear — хочет ли клиент новое/актуальное КП.
     */
    private function classify(OpenAIChatService $ai, EmailMessage $message): string
    {
        $body = trim((string) ($message->body_plain ?: strip_tags((string) $message->body_html)));
        $body = mb_substr($body, 0, 2000);
        if ($body === '') {
            return 'unclear';
        }

        $system = 'Ты классифицируешь ответ клиента на письмо, где мы предложили подготовить актуальное (обновлённое) коммерческое предложение по позициям, на которые снизилась цена. '
            . 'Верни СТРОГО JSON {"wants_new_quote": true|false, "confidence": 0..1}. '
            . 'true — клиент согласен или заинтересован получить новое КП («да», «пришлите», «интересно», «давайте», «актуально», вопрос про цены/сроки/наличие). '
            . 'false — отказ или потребность закрыта («не надо», «уже купили», «не актуально», «спасибо, нет»). '
            . 'Если из текста непонятно — false с низкой confidence.';

        $resp = $ai->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $body],
            ],
            (string) config('services.openai.clarification_model', 'gpt-4o-mini'),
            ['temperature' => 0, 'response_format' => ['type' => 'json_object'], 'max_tokens' => 60],
        );

        $data = json_decode((string) ($resp['content'] ?? ''), true);
        if (! is_array($data)) {
            return 'unclear';
        }
        $wants = (bool) ($data['wants_new_quote'] ?? false);
        $conf = (float) ($data['confidence'] ?? 0);
        if ($wants && $conf >= 0.6) {
            return 'positive';
        }

        return $wants ? 'unclear' : 'negative';
    }
}
