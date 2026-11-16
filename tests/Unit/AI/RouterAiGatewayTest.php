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

    public function test_task_mapping_wins_and_unmapped_models_stay_openai(): void
    {
        config([
            'services.routerai.by_caller' => '{"RequestItemParsingService":"google/gemini-3.1-flash-lite"}',
            'services.routerai.models' => '{}',
            'services.routerai.default_model' => '',
        ]);

        $this->assertSame('google/gemini-3.1-flash-lite', RouterAiGateway::modelFor('gpt-4o', 'RequestItemParsingService'));
        $this->assertSame('openai/gpt-4o', RouterAiGateway::modelFor('gpt-4o', 'SupplierOfferParser'));
        $this->assertSame('openai/gpt-4o-mini', RouterAiGateway::modelFor('gpt-4o-mini', 'MailCategoryClassifier'));
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

    public function test_gpt_models_get_no_reasoning_parameter(): void
    {
        config(['services.routerai.models' => '{"gpt-4o":"openai/gpt-4o"}']);
        Http::fake(['router.test/*' => Http::response(['choices' => [['message' => ['content' => 'ок']]], 'usage' => []])]);

        app(OpenAIChatService::class)->chat([['role' => 'user', 'content' => 'да?']], 'gpt-4o');

        Http::assertSent(fn ($r) => $r['model'] === 'openai/gpt-4o' && ! isset($r['reasoning']));
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
            ['name' => 'Ролик', 'article' => 'gаа 123', 'qty' => 4], // «аа» — кириллица
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

    public function test_quote_lines_check_price_of_the_matched_line(): void
    {
        $gold = [
            ['name' => 'Ролик направляющий ARO', 'article' => 'M07014', 'qty' => 11.0, 'price' => 9630.31],
            ['name' => 'Башмак кабины', 'article' => null, 'qty' => 2.0, 'price' => 1500.0],
        ];
        $items = [
            ['name' => 'Башмак кабины', 'article' => null, 'qty' => 2, 'price' => 1250.0],
            ['name' => 'Ролик ARO', 'article' => 'M07014', 'qty' => 11, 'price' => 9630.31],
        ];

        $m = \App\Console\Commands\LlmCompareTasksCommand::matchLines($gold, $items);

        $this->assertSame(2, $m['matched']);
        $this->assertSame(1, $m['price_ok']);
        $this->assertCount(1, $m['wrong']);
    }

    public function test_captured_answers_compare_values_not_text_or_order(): void
    {
        $gpt = ['assignments' => [
            ['image_index' => 0, 'slug' => 'nameplate', 'confidence' => 0.9, 'description' => 'шильдик платы крупно'],
            ['image_index' => 1, 'slug' => 'general_view', 'confidence' => 0.7, 'description' => 'общий вид'],
        ]];
        $candidate = ['assignments' => [
            ['image_index' => 1, 'slug' => 'general_view', 'confidence' => 0.5, 'description' => 'вид сбоку'],
            ['image_index' => 0, 'slug' => 'connector', 'confidence' => 0.8, 'description' => 'разъём'],
        ]];

        $a = \App\Console\Commands\LlmCompareCapturedCommand::facts($gpt);
        $b = \App\Console\Commands\LlmCompareCapturedCommand::facts($candidate);

        $this->assertSame(['.assignments[].image_index=0', '.assignments[].slug=nameplate', '.assignments[].image_index=1', '.assignments[].slug=general_view'], $a);
        $this->assertEqualsWithDelta(0.75, \App\Console\Commands\LlmCompareCapturedCommand::f1($a, $b), 0.001);
    }

    public function test_similar_items_are_told_apart_by_numbers(): void
    {
        $gold = [
            ['name' => 'Реле промежуточное РЭП 34-22-11 110В', 'article' => null, 'qty' => 9.0],
            ['name' => 'Реле промежуточное РЭП 34-31-11 110В', 'article' => null, 'qty' => 2.0],
        ];
        $items = [
            ['name' => 'Реле промежуточное электромагнитное РЭП 34-31-11 110В', 'article' => null, 'qty' => 2],
            ['name' => 'Реле промежуточное электромагнитное РЭП 34-22-11 110В', 'article' => null, 'qty' => 9],
        ];

        $this->assertSame(2, LlmCompareParsingCommand::match($gold, $items)['qty_ok']);
    }
}
