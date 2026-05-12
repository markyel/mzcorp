<?php

namespace App\Services\Web;

use App\Models\InboundUrlFetch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Веб-фетч URL'ов из входящих писем — с SSRF-гардом, кэшем по
 * `inbound_url_fetches` и бюджетом времени на письмо.
 *
 * Pipeline на один URL:
 *  1. normalizeUrl (через WebSecurity)
 *  2. cache lookup по url_hash → если свежий success, отдаём из кэша
 *  3. isHostSafe (резолв + SSRF check)
 *  4. Http::head() — проверка Content-Type и Content-Length
 *  5. Http::get() с CURLOPT_RANGE + max-redirects + per-URL timeout
 *  6. Извлечение читаемого текста (title + og + visible body)
 *  7. Запись в `inbound_url_fetches`
 *
 * Все исключения swallowed → результат отражается в status поле,
 * вызывающий код видит структурированный результат, а не throw.
 *
 * Бюджет per-email задаётся через config('services.web_fetch.budget_seconds').
 */
class InboundUrlFetcherService
{
    public function __construct(private readonly WebSecurity $sec)
    {
    }

    /**
     * Сфетчить список URL'ов. Возвращает выжимки в том же порядке.
     *
     * @param list<string> $normalizedUrls — уже прошедшие normalizeUrl
     * @return list<InboundUrlFetch>
     */
    public function fetchMany(array $normalizedUrls): array
    {
        $budget = (float) config('services.web_fetch.budget_seconds', 60);
        $deadline = microtime(true) + $budget;

        $result = [];
        foreach ($normalizedUrls as $url) {
            if (microtime(true) >= $deadline) {
                $result[] = $this->saveResult($url, InboundUrlFetch::STATUS_SKIPPED_BUDGET, [
                    'error_message' => 'per-email budget exhausted',
                ]);
                continue;
            }
            $result[] = $this->fetchOne($url);
        }

        return $result;
    }

    public function fetchOne(string $normalizedUrl): InboundUrlFetch
    {
        $cached = $this->cacheLookup($normalizedUrl);
        if ($cached !== null) {
            return $cached;
        }

        $parts = parse_url($normalizedUrl);
        $host = $parts['host'] ?? '';

        if (! $this->sec->isHostSafe($host)) {
            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_SSRF_BLOCKED, [
                'host' => $host,
                'error_message' => 'host resolves to non-public or unsafe address',
            ]);
        }

        $perUrlTimeout = (int) config('services.web_fetch.url_timeout', 10);
        $maxSize = (int) config('services.web_fetch.max_size_bytes', 2 * 1024 * 1024);
        $allowedTypes = (array) config('services.web_fetch.allowed_content_types', [
            'text/html', 'application/xhtml+xml', 'text/plain',
        ]);
        $userAgent = (string) config('services.web_fetch.user_agent', 'MyLift-Bot/1.0 (+https://mzcorp.ru)');

        try {
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,text/plain;q=0.8,*/*;q=0.1',
                'Accept-Language' => 'ru,en;q=0.8',
            ])
                ->timeout($perUrlTimeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => false,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function ($request, $response, $uri) {
                            $host = $uri->getHost();
                            if (! $this->sec->isHostSafe($host)) {
                                // Прерываем редирект — Guzzle на возврат false не реагирует,
                                // но кинет TooManyRedirects если занулим. Бросаем явно.
                                throw new \RuntimeException("redirect to unsafe host: {$host}");
                            }
                        },
                    ],
                    'curl' => [
                        \CURLOPT_RANGE => '0-' . ($maxSize - 1),
                    ],
                ])
                ->get($normalizedUrl);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_TIMEOUT, [
                'host' => $host,
                'error_message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('InboundUrlFetcher: fetch failed', [
                'url' => $normalizedUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_HTTP_ERROR, [
                'host' => $host,
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);
        }

        $status = $response->status();
        $contentType = strtolower((string) $response->header('Content-Type'));
        // Отрежем charset/boundary часть: `text/html; charset=UTF-8`
        $primaryType = trim(explode(';', $contentType)[0] ?? '');

        if (! $response->successful()) {
            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_HTTP_ERROR, [
                'host' => $host,
                'http_status' => $status,
                'content_type' => $primaryType ?: null,
                'error_message' => "HTTP {$status}",
            ]);
        }

        if (! in_array($primaryType, $allowedTypes, true)) {
            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_WRONG_CONTENT_TYPE, [
                'host' => $host,
                'http_status' => $status,
                'content_type' => $primaryType ?: null,
                'error_message' => "content-type not allowed: {$primaryType}",
            ]);
        }

        $body = (string) $response->body();
        if (strlen($body) > $maxSize) {
            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_SIZE_EXCEEDED, [
                'host' => $host,
                'http_status' => $status,
                'content_type' => $primaryType,
                'content_length' => strlen($body),
                'error_message' => 'body exceeds max_size',
            ]);
        }

        try {
            $text = $this->extractReadableText($body, $primaryType);
        } catch (\Throwable $e) {
            return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_PARSE_ERROR, [
                'host' => $host,
                'http_status' => $status,
                'content_type' => $primaryType,
                'content_length' => strlen($body),
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);
        }

        return $this->saveResult($normalizedUrl, InboundUrlFetch::STATUS_SUCCESS, [
            'host' => $host,
            'http_status' => $status,
            'content_type' => $primaryType,
            'content_length' => strlen($body),
            'extracted_text' => $text,
        ]);
    }

    /**
     * Парсинг страницы → читаемый текст. Конкатенируем title + meta og:* +
     * meta description + видимый body, выкидывая script/style/noscript.
     * Truncate до cfg.max_text_chars.
     */
    private function extractReadableText(string $body, string $contentType): string
    {
        if ($contentType === 'text/plain') {
            return $this->truncate($body);
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING);

            $segments = [];

            // <title>
            $titles = $doc->getElementsByTagName('title');
            if ($titles->length > 0) {
                $t = trim($titles->item(0)->textContent ?? '');
                if ($t !== '') {
                    $segments[] = 'TITLE: ' . $t;
                }
            }

            // <meta name="description" content="">
            // <meta property="og:title|og:description|og:site_name" content="">
            $metas = $doc->getElementsByTagName('meta');
            foreach ($metas as $m) {
                /** @var \DOMElement $m */
                $name = strtolower($m->getAttribute('name'));
                $prop = strtolower($m->getAttribute('property'));
                $key = $name !== '' ? $name : $prop;
                if (! in_array($key, ['description', 'og:title', 'og:description', 'og:site_name', 'keywords'], true)) {
                    continue;
                }
                $val = trim($m->getAttribute('content'));
                if ($val !== '') {
                    $segments[] = strtoupper($key) . ': ' . $val;
                }
            }

            // Удаляем неинформативные теги
            foreach (['script', 'style', 'noscript', 'iframe', 'svg', 'template'] as $tag) {
                $nodes = iterator_to_array($doc->getElementsByTagName($tag));
                foreach ($nodes as $n) {
                    $n->parentNode?->removeChild($n);
                }
            }

            // <body> текст
            $bodies = $doc->getElementsByTagName('body');
            if ($bodies->length > 0) {
                $bodyText = $this->normalizeWhitespace($bodies->item(0)->textContent ?? '');
                if ($bodyText !== '') {
                    $segments[] = 'BODY: ' . $bodyText;
                }
            }

            return $this->truncate(implode("\n", $segments));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t\f\v\x{00A0}]+/u', ' ', $text);
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function truncate(string $text): string
    {
        $max = (int) config('services.web_fetch.max_text_chars', 8000);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "\n…[truncated]";
    }

    private function cacheLookup(string $normalizedUrl): ?InboundUrlFetch
    {
        $hash = $this->sec->hashUrl($normalizedUrl);
        $row = InboundUrlFetch::query()->where('url_hash', $hash)->first();
        if ($row === null) {
            return null;
        }
        // Используем кэш только если запись свежая И успешная. Старые fail-ы
        // позволяем перезапросить (сайт мог восстановиться).
        if ($row->isFresh() && $row->isSuccessful()) {
            return $row;
        }
        // Свежий ssrf_blocked тоже не дёргаем повторно — DNS не изменится за TTL.
        if ($row->isFresh() && $row->status === InboundUrlFetch::STATUS_SSRF_BLOCKED) {
            return $row;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function saveResult(string $normalizedUrl, string $status, array $fields = []): InboundUrlFetch
    {
        $ttlDays = (int) config('services.web_fetch.cache_ttl_days', 7);
        $now = Carbon::now();
        $payload = array_merge([
            'url_hash' => $this->sec->hashUrl($normalizedUrl),
            'url' => $normalizedUrl,
            'status' => $status,
            'fetched_at' => $now,
            'expires_at' => $now->copy()->addDays($ttlDays),
        ], $fields);

        return InboundUrlFetch::updateOrCreate(
            ['url_hash' => $payload['url_hash']],
            $payload,
        );
    }
}
