<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Маршрут к OpenAI: прокси с X-Proxy-Key (ai2, ai3…) или прямой выход на api.openai.com через
 * HTTP-прокси (CONNECT), без X-Proxy-Key. Ключ маршрута стабильный и без секретов: base_url прокси
 * или direct:{хост:порт} — по нему журнал (OpenAIRouteJournal) хранит его состояние.
 */
final class OpenAIRoute
{
    public const OPENAI_URL = 'https://api.openai.com';

    private function __construct(
        public readonly string $key,
        public readonly string $url,
        public readonly ?string $httpProxy,
    ) {}

    public static function proxy(string $baseUrl): self
    {
        $url = rtrim(trim($baseUrl), '/');

        return new self($url, $url, null);
    }

    /** Прямой выход через HTTP-прокси: «http://user:pass@host:3128» — в ключ идёт только хост и порт. */
    public static function direct(string $httpProxy): self
    {
        $proxy = trim($httpProxy);
        $parts = parse_url(str_contains($proxy, '://') ? $proxy : 'http://'.$proxy) ?: [];
        $hostPort = ($parts['host'] ?? $proxy).(isset($parts['port']) ? ':'.$parts['port'] : '');

        return new self('direct:'.$hostPort, self::OPENAI_URL, $proxy);
    }

    public function isDirect(): bool
    {
        return $this->httpProxy !== null;
    }

    /** Заголовки маршрута: прямому выходу X-Proxy-Key не нужен — не отдаём его чужому прокси. */
    public function headers(array $headers): array
    {
        return $this->isDirect() ? array_diff_key($headers, ['X-Proxy-Key' => true]) : $headers;
    }

    /** Опции Guzzle маршрута: прямому — HTTP-прокси. */
    public function options(): array
    {
        return $this->isDirect() ? ['proxy' => $this->httpProxy] : [];
    }

    /** Запрос по маршруту: заголовки без лишнего и прокси, если он прямой. */
    public function http(array $headers): PendingRequest
    {
        return Http::withHeaders($this->headers($headers))->withOptions($this->options());
    }

    /** То же для запроса из Http::pool(). */
    public function apply(PendingRequest $request, array $headers): PendingRequest
    {
        return $request->withHeaders($this->headers($headers))->withOptions($this->options());
    }
}
