<?php

namespace Tests\Unit\AI;

use App\Services\AI\OpenAIProxyPool;
use App\Services\AI\OpenAIRoute;
use App\Services\AI\OpenAIRouteJournal;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Журнал доступности маршрутов к OpenAI (ТЗ 12.11.2026, §7): очередь, сглаживание, пометка при сбое, проверка. */
class OpenAIRouteJournalTest extends TestCase
{
    private function routes(): array
    {
        return [OpenAIRoute::proxy('https://ai2.example'), OpenAIRoute::proxy('https://ai3.example'),
            OpenAIRoute::proxy('https://ai5.example'), OpenAIRoute::direct('http://user:secret@10.0.0.9:3128')];
    }

    private function journal(array $routes, ?Carbon $probedAt = null): array
    {
        return ['probed_at' => ($probedAt ?? now())->toIso8601String(), 'routes' => $routes];
    }

    private static function keys(array $routes): array
    {
        return array_map(fn (OpenAIRoute $r) => $r->key, $routes);
    }

    public function test_alive_routes_go_by_speed_dead_are_left_out_new_come_after_alive(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $j = $this->journal([
            'https://ai2.example' => ['ok' => true, 'latency_ms' => 900],
            'https://ai3.example' => ['ok' => false, 'latency_ms' => 100],
            'https://ai5.example' => ['ok' => true, 'latency_ms' => 300],
        ]);

        $this->assertSame(['https://ai5.example', 'https://ai2.example', 'direct:10.0.0.9:3128'],
            self::keys(OpenAIRouteJournal::order($this->routes(), $j, now())));
    }

    public function test_equal_speed_keeps_the_config_order(): void
    {
        $j = $this->journal(['https://ai2.example' => ['ok' => true, 'latency_ms' => 500], 'https://ai3.example' => ['ok' => true, 'latency_ms' => 500],
            'https://ai5.example' => ['ok' => true, 'latency_ms' => 500], 'direct:10.0.0.9:3128' => ['ok' => true, 'latency_ms' => 500]]);

        $this->assertSame(self::keys($this->routes()), self::keys(OpenAIRouteJournal::order($this->routes(), $j, now())));
    }

    public function test_empty_stale_or_all_dead_journal_gives_the_config_order(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 12:00:00'));
        $config = self::keys($this->routes());
        $dead = ['ok' => false, 'latency_ms' => 100];
        $fast = ['https://ai5.example' => ['ok' => true, 'latency_ms' => 100]];

        $this->assertSame($config, self::keys(OpenAIRouteJournal::order($this->routes(), ['probed_at' => null, 'routes' => []], now())));
        // Три пропущенных опроса: журналу не верим.
        $this->assertSame($config, self::keys(OpenAIRouteJournal::order($this->routes(), $this->journal($fast, now()->subMinutes(46)), now())));
        $this->assertSame('https://ai5.example', OpenAIRouteJournal::order($this->routes(), $this->journal($fast, now()->subMinutes(44)), now())[0]->key);
        $this->assertSame($config, self::keys(OpenAIRouteJournal::order($this->routes(), $this->journal(array_fill_keys($config, $dead)), now())));
    }

    public function test_latency_is_smoothed_on_successes_only(): void
    {
        $now = Carbon::parse('2026-11-12 12:00:00');
        $e = OpenAIRouteJournal::next(null, true, 1000, null, $now);
        $e = OpenAIRouteJournal::next($e, true, 2000, null, $now);
        $this->assertSame(1400, $e['latency_ms']);
        $this->assertSame(2000, $e['last_ms']);

        $down = OpenAIRouteJournal::next($e, false, 20000, 'timeout', $now->copy()->addMinutes(15));
        $this->assertSame(1400, $down['latency_ms']);
        $this->assertFalse($down['ok']);
        $this->assertSame(1, $down['fails']);
        $this->assertSame($now->copy()->addMinutes(15)->toIso8601String(), $down['since']);
        $this->assertSame(2, OpenAIRouteJournal::next($down, false, null, 'again', $now->copy()->addMinutes(30))['fails']);
        $this->assertSame($down['since'], OpenAIRouteJournal::next($down, false, null, 'again', $now->copy()->addMinutes(30))['since']);
    }

    public function test_a_dropped_connection_kills_the_route_a_slow_answer_does_not(): void
    {
        $this->assertTrue(OpenAIRouteJournal::failureKillsRoute(null, new ConnectionException('cURL error 7: Failed to connect to ai3.lazylift.ru port 8443 after 5002 ms')));
        $this->assertTrue(OpenAIRouteJournal::failureKillsRoute(null, new ConnectionException('cURL error 6: Could not resolve host: ai9.example')));
        $this->assertFalse(OpenAIRouteJournal::failureKillsRoute(null, new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received')));
        $this->assertTrue(OpenAIRouteJournal::failureKillsRoute($this->response(502, '<html>502 Bad Gateway</html>')));
        $this->assertFalse(OpenAIRouteJournal::failureKillsRoute($this->response(429, '{"error":{"message":"Rate limit","code":"rate_limit_exceeded"}}')));
        $this->assertFalse(OpenAIRouteJournal::failureKillsRoute($this->response(400, '{"error":{"message":"bad request"}}')));
    }

    public function test_openai_answering_400_is_alive_the_proxy_refusing_is_dead(): void
    {
        $this->assertTrue(OpenAIRouteJournal::classify($this->response(400, '{"error":{"message":"[] is too short - \'messages\'","code":"empty_array"}}'))['ok']);
        $this->assertFalse(OpenAIRouteJournal::classify($this->response(403, '{"error":"Unauthorized"}'))['ok']);
        $this->assertFalse(OpenAIRouteJournal::classify($this->response(404, '<html>Not Found</html>'))['ok']);
        $this->assertFalse(OpenAIRouteJournal::classify($this->response(503, '{"error":{"message":"overloaded"}}'))['ok']);
        $this->assertFalse(OpenAIRouteJournal::classify(null, new ConnectionException('Connection refused'))['ok']);
    }

    public function test_a_dead_route_is_skipped_at_once_and_marked(): void
    {
        Cache::flush();
        config(['services.openai.base_urls' => 'https://ai3.example,https://ai2.example', 'services.openai.direct_proxies' => '']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'ai3.example')) {
                throw new ConnectionException('cURL error 7: Failed to connect to ai3.example port 443');
            }

            return Http::response(['choices' => [['message' => ['content' => 'ok']]]]);
        });
        $pool = app(OpenAIProxyPool::class);

        [$response] = $pool->request(fn (OpenAIRoute $r) => $r->http([])->post($r->url.'/v1/chat/completions', []));

        $this->assertSame('ok', $response->json('choices.0.message.content'));
        $this->assertFalse(app(OpenAIRouteJournal::class)->all()['routes']['https://ai3.example']['ok']);
        // Опрос отметил ai2 живым; ai3 до следующего опроса — мёртв и в очередь не попадает.
        app(OpenAIRouteJournal::class)->record(OpenAIRoute::proxy('https://ai2.example'), true, 800, null);
        app(OpenAIRouteJournal::class)->finishProbe(['https://ai3.example', 'https://ai2.example']);
        $this->assertSame(['https://ai2.example'], self::keys($pool->candidates()), 'the dead one is out of the queue');
    }

    public function test_a_direct_route_hides_credentials_and_the_proxy_key(): void
    {
        $route = OpenAIRoute::direct('http://user:secret@10.0.0.9:3128');
        $this->assertSame('direct:10.0.0.9:3128', $route->key);
        $this->assertSame('https://api.openai.com', $route->url);
        $this->assertSame(['Authorization' => 'Bearer k'], $route->headers(['Authorization' => 'Bearer k', 'X-Proxy-Key' => 'p']));

        config(['services.openai.api_key' => 'sk-live-ABCDEF123456', 'services.openai.proxy_key' => 'proxykey-777']);
        $clean = OpenAIRouteJournal::clean('Bearer sk-live-ABCDEF123456 via http://user:secret@10.0.0.9:3128 key proxykey-777 '.str_repeat('x', 300));
        $this->assertStringNotContainsString('ABCDEF123456', $clean);
        $this->assertStringNotContainsString('secret', $clean);
        $this->assertStringNotContainsString('proxykey-777', $clean);
        $this->assertLessThanOrEqual(200, mb_strlen($clean));
    }

    private function response(int $status, string $body): Response
    {
        return new Response(new Psr7Response($status, [], $body));
    }
}
