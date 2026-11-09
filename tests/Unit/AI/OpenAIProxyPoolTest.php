<?php

namespace Tests\Unit\AI;

use App\Services\AI\OpenAIProxyPool;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class OpenAIProxyPoolTest extends TestCase
{
    public function test_list_keeps_order_and_drops_blanks_slashes_and_repeats(): void
    {
        $this->assertSame(
            ['https://ai2.lazylift.ru', 'https://185.106.95.203'],
            OpenAIProxyPool::parseUrls(' https://ai2.lazylift.ru/ ,,https://185.106.95.203, https://ai2.lazylift.ru', 'https://api.openai.com'),
        );
    }

    public function test_empty_list_falls_back_to_single_base_url(): void
    {
        $this->assertSame(['https://ai2.lazylift.ru'], OpenAIProxyPool::parseUrls('', 'https://ai2.lazylift.ru/'));
        $this->assertSame(['https://ai2.lazylift.ru'], OpenAIProxyPool::parseUrls(null, 'https://ai2.lazylift.ru'));
    }

    public function test_no_connection_is_a_proxy_failure(): void
    {
        $this->assertTrue(OpenAIProxyPool::isProxyFailure(null, new ConnectionException('Timeout was reached')));
        $this->assertFalse(OpenAIProxyPool::isProxyFailure(null));
    }

    public function test_page_from_the_proxy_itself_is_a_failure(): void
    {
        $this->assertTrue(OpenAIProxyPool::isProxyFailure($this->response(403, '<html><body>403 Forbidden</body></html>')));
        $this->assertTrue(OpenAIProxyPool::isProxyFailure($this->response(502, '<html>502 Bad Gateway</html>')));
        $this->assertTrue(OpenAIProxyPool::isProxyFailure($this->response(403, '{"error":"forbidden"}')));
    }

    public function test_openai_refusing_the_proxy_country_is_a_failure(): void
    {
        $this->assertTrue(OpenAIProxyPool::isProxyFailure($this->response(403,
            '{"error":{"code":"unsupported_country_region_territory","message":"Country, region, or territory not supported"}}')));
    }

    public function test_openai_own_errors_and_success_do_not_switch(): void
    {
        $this->assertFalse(OpenAIProxyPool::isProxyFailure($this->response(200, '{"object":"list","data":[]}')));
        $this->assertFalse(OpenAIProxyPool::isProxyFailure($this->response(429, '{"error":{"code":"insufficient_quota","message":"You exceeded your current quota"}}')));
        $this->assertFalse(OpenAIProxyPool::isProxyFailure($this->response(400, '{"error":{"code":"invalid_request_error","message":"bad"}}')));
        $this->assertFalse(OpenAIProxyPool::isProxyFailure($this->response(503, '{"error":{"code":null,"message":"The engine is currently overloaded"}}')));
    }

    private function response(int $status, string $body): Response
    {
        return new Response(new Psr7Response($status, [], $body));
    }
}
