<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Перебор прокси к OpenAI. Прокси живут недолго: ai.lazylift.ru, Cloudflare Worker, ai3 —
 * каждый раз падал один адрес из OPENAI_BASE_URL, и до ручной правки .env весь ИИ шёл через
 * платный резерв (09.11.2026: ai3 не отвечал с ночи, ai2 и ai5 при этом работали).
 *
 * OPENAI_BASE_URLS — адреса через запятую по приоритету, ключ прокси у всех один
 * (OPENAI_PROXY_KEY). Запрос идёт в первый живой; не ответил он сам (нет связи, страница
 * nginx вместо ответа OpenAI, OpenAI не пускает страну выхода) — в следующий, а отказавший
 * пропускается OPENAI_PROXY_COOLDOWN_MINUTES минут. Ошибка самого OpenAI (нет денег, плохой
 * запрос, перегрузка) не переключает: через другой прокси ответ был бы тот же.
 * Отказали все — решает вызывающий: резервный шлюз (OpenAIFallbackGateway) или ошибка.
 *
 * `ai:proxies --probe` раз в 5 минут проверяет каждый прокси и возвращает оживших.
 */
class OpenAIProxyPool
{
    private const CACHE_DOWN = 'openai:proxy:down:';

    /**
     * Адреса прокси по приоритету: OPENAI_BASE_URLS, иначе один OPENAI_BASE_URL.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        return self::parseUrls(config('services.openai.base_urls'), (string) config('services.openai.base_url'));
    }

    /**
     * @param  string|list<string>|null  $list
     * @return list<string>
     */
    public static function parseUrls(string|array|null $list, string $single): array
    {
        $items = is_array($list) ? $list : explode(',', (string) $list);
        $urls = array_values(array_unique(array_filter(array_map(fn ($u) => rtrim(trim((string) $u), '/'), $items))));

        return $urls !== [] ? $urls : [rtrim(trim($single), '/')];
    }

    /**
     * Живые прокси по порядку. Отказали все: есть резерв — пусто (сразу в резерв, не ждать
     * таймаутов каждого), резерва нет — все (лучше попытка, чем гарантированная ошибка).
     *
     * @return list<string>
     */
    public function candidates(bool $hasReserve = false): array
    {
        $alive = array_values(array_filter($this->urls(), fn (string $url) => ! $this->isDown($url)));

        return $alive !== [] || $hasReserve ? $alive : $this->urls();
    }

    /** Адрес для вызовов без перебора (картинки, проверка тона): первый живой. */
    public function current(): string
    {
        return $this->candidates()[0];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = [
            'Authorization' => 'Bearer '.config('services.openai.api_key'),
            'Content-Type' => 'application/json',
        ];
        if ((string) config('services.openai.proxy_key') !== '') {
            $headers['X-Proxy-Key'] = (string) config('services.openai.proxy_key');
        }

        return $headers;
    }

    /**
     * Запрос через прокси по очереди до первого, кто ответил сам (успехом или ошибкой OpenAI).
     * Ответ — как у одиночного запроса: [ответ, ошибка соединения] последней попытки.
     *
     * @param  callable(string): Response  $send  получает адрес прокси без «/» в конце
     * @return array{0: ?Response, 1: ?ConnectionException}
     */
    public function request(callable $send, bool $hasReserve = false): array
    {
        $candidates = $this->candidates($hasReserve);
        if ($candidates === []) {
            return [null, new ConnectionException('All OpenAI proxies are marked down')];
        }

        $response = null;
        $error = null;
        foreach ($candidates as $url) {
            $response = null;
            $error = null;
            try {
                $response = $send($url);
            } catch (ConnectionException $e) {
                $error = $e;
            }

            if (! self::isProxyFailure($response, $error)) {
                $this->markUp($url);

                return [$response, null];
            }
            $this->markDown($url, $response !== null ? $response->status().' '.mb_substr($response->body(), 0, 200) : (string) $error?->getMessage());
        }

        return [$response, $error];
    }

    /**
     * Отказал ли сам прокси. Ответ OpenAI об ошибке — JSON с объектом «error»; всё прочее
     * (страница nginx 403/502, «forbidden» прокси) — от прокси. OpenAI 403 «unsupported
     * country» — тоже прокси: не пускают его страну выхода (Cloudflare Worker, 30.07.2026).
     */
    public static function isProxyFailure(?Response $response, ?Throwable $error = null): bool
    {
        if ($response === null) {
            return $error !== null;
        }
        if ($response->successful()) {
            return false;
        }
        $openAiError = $response->json('error');
        if (! is_array($openAiError)) {
            return true;
        }

        return ($openAiError['code'] ?? null) === 'unsupported_country_region_territory';
    }

    /**
     * Проверка прокси эмбеддингом слова в 8 измерениях (доли цента в сутки). Не список моделей:
     * ai2 пропускает только рабочие пути, /v1/models у него 404.
     *
     * @return array{ok: bool, detail: string, ms: int}
     */
    public function probe(string $url): array
    {
        $started = microtime(true);
        $response = null;
        $error = null;
        try {
            $response = Http::withHeaders($this->headers())->connectTimeout(5)->timeout(15)->post($url.'/v1/embeddings', [
                'model' => (string) config('services.openai.embedding_model', 'text-embedding-3-small'),
                'input' => 'ping',
                'dimensions' => 8,
            ]);
        } catch (ConnectionException $e) {
            $error = $e;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $ok = $response !== null && $response->successful();
        $detail = $response !== null ? 'HTTP '.$response->status() : mb_substr((string) $error?->getMessage(), 0, 160);

        if ($ok) {
            $this->markUp($url);
        } elseif (self::isProxyFailure($response, $error)) {
            $this->markDown($url, $detail.($response !== null ? ' '.mb_substr($response->body(), 0, 200) : ''));
        }

        return ['ok' => $ok, 'detail' => $detail, 'ms' => $ms];
    }

    public function isDown(string $url): bool
    {
        return Cache::has(self::CACHE_DOWN.md5($url));
    }

    /** @return array{at: string, why: string}|null */
    public function downInfo(string $url): ?array
    {
        $info = Cache::get(self::CACHE_DOWN.md5($url));

        return is_array($info) ? $info : null;
    }

    public function markDown(string $url, string $why): void
    {
        $minutes = max(1, (int) config('services.openai.proxy_cooldown_minutes', 10));
        $info = ['at' => now()->toIso8601String(), 'why' => mb_substr($why, 0, 300)];
        if (Cache::add(self::CACHE_DOWN.md5($url), $info, now()->addMinutes($minutes))) {
            Log::warning('OpenAI proxy unavailable — requests go to the next one', ['proxy' => $url, 'why' => $info['why'], 'skip_minutes' => $minutes]);
        }
    }

    public function markUp(string $url): void
    {
        if ($this->isDown($url)) {
            Cache::forget(self::CACHE_DOWN.md5($url));
            Log::info('OpenAI proxy is back', ['proxy' => $url]);
        }
    }
}
