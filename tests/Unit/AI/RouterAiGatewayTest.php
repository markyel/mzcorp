<?php

namespace Tests\Unit\AI;

use App\Console\Commands\LlmCompareParsingCommand;
use App\Services\AI\OpenAIChatService;
use App\Services\AI\RouterAiGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** RouterAI основным каналом (16.11.2026): замена модели, переход на прямой OpenAI, сравнение разбора. */
class RouterAiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.routerai.enabled' => true,
            'services.routerai.api_key' => 'test-key',
            'services.routerai.base_url' => 'https://router.test/api/v1',
            'services.routerai.models' => '{"gpt-4o":"qwen/qwen3.6-27b"}',
            'services.routerai.default_model' => 'google/gemini-3.1-flash-lite',
            'services.routerai.no_fallback' => false,
            'services.openai.api_key' => 'sk-test',
            'services.openai.proxy_key' => null,
            'services.openai.fallback.api_key' => null,
        ]);
    }

    public function test_model_is_mapped_or_default(): void
    {
        $this->assertSame('qwen/qwen3.6-27b', RouterAiGateway::modelFor('gpt-4o'));
        $this->assertSame('google/gemini-3.1-flash-lite', RouterAiGateway::modelFor('gpt-4o-mini'));
    }

    public function test_disabled_without_key(): void
    {
        config(['services.routerai.api_key' => '']);
        $this->assertFalse(app(RouterAiGateway::class)->enabled());
    }

    public function test_answer_goes_through_router_with_mapped_model_and_raised_limit(): void
    {
        Http::fake(['router.test/*' => Http::response(['model' => 'qwen/qwen3.6-27b', 'choices' => [['message' => ['content' => 'ок']]], 'usage' => []])]);

        $answer = app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'да?']], 'gpt-4o', ['max_tokens' => 20]);

        $this->assertSame('ок', $answer['content']);
        Http::assertSent(fn ($r) => $r['model'] === 'qwen/qwen3.6-27b' && $r['max_tokens'] === 1500 && $r['reasoning'] === ['enabled' => false]);
    }

    public function test_router_error_or_empty_answer_falls_back_to_openai(): void
    {
        Http::fake([
            'router.test/*' => Http::response(['choices' => [['message' => ['content' => '']]]]),
            '*' => Http::response(['model' => 'gpt-4o', 'choices' => [['message' => ['content' => 'от OpenAI']]], 'usage' => []]),
        ]);

        $answer = app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'да?']], 'gpt-4o');

        $this->assertSame('от OpenAI', $answer['content']);
    }

    public function test_comparison_mode_does_not_fall_back(): void
    {
        config(['services.routerai.no_fallback' => true]);
        Http::fake(['router.test/*' => Http::response(['error' => 'нет модели'], 400)]);
        OpenAIChatService::$errors = [];

        try {
            app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'да?']], 'gpt-4o');
            $this->fail('ожидалась ошибка');
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, OpenAIChatService::$errors);
        Http::assertSentCount(1);
    }

    public function test_items_are_matched_by_article_then_by_name(): void
    {
        $gold = [
            ['name' => 'Ролик дверной Otis 60 мм', 'article' => 'GAA-123', 'qty' => 4.0],
            ['name' => 'Кнопка вызова латунная', 'article' => null, 'qty' => 2.0],
            ['name' => 'Тросик ограничителя скорости', 'article' => null, 'qty' => 1.0],
        ];
        $items = [
            ['name' => 'Ролик', 'article' => 'gaa 123', 'qty' => 4],
            ['name' => 'кнопка вызова латунная', 'article' => null, 'qty' => 1],
            ['name' => 'Лишняя позиция', 'article' => null, 'qty' => 1],
        ];

        $m = LlmCompareParsingCommand::match($gold, $items);

        $this->assertSame(2, $m['matched']);
        $this->assertSame(1, $m['qty_ok']);
        $this->assertSame(1, $m['article_ok']);
        $this->assertSame(['Тросик ограничителя скорости'], $m['missed']);
        $this->assertSame(['Лишняя позиция'], $m['extra']);
    }
}
