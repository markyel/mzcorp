<?php

namespace Tests\Feature\QuoteParts;

use App\Jobs\QuoteParts\HandleQuotePartsEventJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuotePartsWebhookTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.quoteparts.webhook_secret' => self::SECRET, 'services.quoteparts.webhook_tolerance' => 300]);
    }

    private function deliver(array $payload, ?string $secret = self::SECRET, ?int $at = null)
    {
        $body = json_encode($payload);
        $ts = (string) ($at ?? time());

        return $this->call('POST', '/api/quoteparts/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_QUOTEPARTS_TIMESTAMP' => $ts,
            'HTTP_X_QUOTEPARTS_SIGNATURE' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, (string) $secret),
        ], $body);
    }

    public function test_signed_event_is_queued(): void
    {
        $this->deliver(['event' => 'supplier.question', 'rfq_id' => 42, 'supplier' => 'org:7', 'questions' => ['Which voltage?']])
            ->assertStatus(202);

        Queue::assertPushed(HandleQuotePartsEventJob::class, fn ($job) => $job->remoteId === 42
            && $job->event === 'supplier.question'
            && $job->supplierKey === 'org:7'
            && $job->questions === ['Which voltage?']);
    }

    public function test_wrong_signature_or_stale_timestamp_is_refused(): void
    {
        $this->deliver(['event' => 'rfq.updated', 'rfq_id' => 42], 'another-secret')->assertStatus(401);
        $this->deliver(['event' => 'rfq.updated', 'rfq_id' => 42], self::SECRET, time() - 3600)->assertStatus(401);

        config(['services.quoteparts.webhook_secret' => '']);
        $this->deliver(['event' => 'rfq.updated', 'rfq_id' => 42])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_unknown_event_is_refused(): void
    {
        $this->deliver(['event' => 'item.deleted', 'rfq_id' => 42])->assertStatus(422);
        $this->deliver(['event' => 'rfq.updated', 'rfq_id' => '42'])->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
