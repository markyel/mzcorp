<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\OpenAIChatService;
use App\Services\AI\OpenAIFallbackGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Основной канал OpenAI недоступен — тот же запрос через резервный шлюз; ошибка запроса — нет. */
class OpenAIFallbackGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.api_key' => 'primary', 'services.openai.base_url' => 'https://primary.test', 'services.openai.proxy_key' => '',
            'services.openai.fallback.api_key' => 'reserve', 'services.openai.fallback.base_url' => 'https://reserve.test',
            'services.openai.fallback.model_prefix' => 'openai/', 'services.openai.fallback.models' => '{"gpt-4.1":"openai/gpt-4.1-2025"}',
        ]);
    }

    public function test_no_money_on_the_primary_goes_through_the_reserve(): void
    {
        Http::fake([
            'primary.test/*' => Http::response(['error' => ['code' => 'insufficient_quota']], 429),
            'reserve.test/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]], 'usage' => []]),
        ]);

        $this->assertSame('OK', app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'hi']], 'gpt-4o-mini')['content']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://reserve.test/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer reserve') && $r['model'] === 'openai/gpt-4o-mini' && $r['max_tokens'] === 4096);
    }

    public function test_a_bad_request_is_not_retried_in_the_reserve(): void
    {
        Http::fake(['primary.test/*' => Http::response(['error' => ['message' => 'bad']], 400), 'reserve.test/*' => Http::response([], 200)]);

        try {
            app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'hi']], 'gpt-4o-mini', ['max_tokens' => 5]);
            $this->fail('the 400 should come through');
        } catch (\RuntimeException) {
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'reserve.test'));
    }

    public function test_without_a_reserve_key_nothing_changes(): void
    {
        config(['services.openai.fallback.api_key' => '']);
        Http::fake(['primary.test/*' => Http::response(['error' => ['code' => 'insufficient_quota']], 429)]);

        $this->expectException(\RuntimeException::class);
        app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'hi']], 'gpt-4o-mini');
    }

    public function test_model_names(): void
    {
        $this->assertSame('openai/gpt-4.1-2025', OpenAIFallbackGateway::modelFor('gpt-4.1'));
        $this->assertSame('openai/gpt-4o', OpenAIFallbackGateway::modelFor('gpt-4o'));
    }
}
