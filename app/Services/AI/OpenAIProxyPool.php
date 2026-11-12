<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Маршруты к OpenAI. Прокси живут недолго: ai.lazylift.ru, Cloudflare Worker, ai3 — каждый раз
 * падал один адрес, и до ручной правки .env весь ИИ шёл через платный резерв (09.11.2026).
 *
 * Маршруты — OPENAI_BASE_URLS (прокси с X-Proxy-Key, по приоритету) и OPENAI_DIRECT_PROXIES (прямой
 * выход на api.openai.com через HTTP-прокси). Очередь — по журналу доступности (OpenAIRouteJournal,
 * 12.11.2026): живые по скорости, мёртвые не используются, журнал опрашивает llm:probe-routes раз в
 * 15 минут. Запрос идёт в первый маршрут; не ответил сам маршрут (нет связи, страница прокси вместо
 * ответа OpenAI, OpenAI не пускает страну выхода) — в следующий, а при обрыве связи или шлюза он сразу
 * помечается мёртвым. Ошибка самого OpenAI (нет денег, плохой запрос, перегрузка) не переключает:
 * через другой маршрут ответ был бы тот же. Отказали все — решает вызывающий: резервный шлюз
 * (OpenAIFallbackGateway) или ошибка.
 */
class OpenAIProxyPool
{
    public function __construct(private readonly OpenAIRouteJournal $journal) {}

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
     * Все маршруты в порядке конфига: прокси, затем прямые выходы.
     *
     * @return list<OpenAIRoute>
     */
    public function routes(): array
    {
        $routes = array_map(fn (string $url) => OpenAIRoute::proxy($url), $this->urls());
        foreach (array_filter(array_map('trim', explode(',', (string) config('services.openai.direct_proxies', '')))) as $proxy) {
            $routes[] = OpenAIRoute::direct($proxy);
        }

        return array_values(collect($routes)->unique(fn (OpenAIRoute $r) => $r->key)->all());
    }

    /**
     * Очередь маршрутов для запроса — по журналу (OpenAIRouteJournal::order).
     *
     * @return list<OpenAIRoute>
     */
    public function candidates(): array
    {
        return OpenAIRouteJournal::order($this->routes(), $this->journal->all(), now());
    }

    /** Маршрут для вызовов без перебора (картинки, проверка тона): первый в очереди. */
    public function current(): OpenAIRoute
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
     * Запрос по маршрутам по очереди до первого, кто ответил сам (успехом или ошибкой OpenAI).
     * Ответ — как у одиночного запроса: [ответ, ошибка соединения] последней попытки.
     *
     * @param  callable(OpenAIRoute): Response  $send
     * @return array{0: ?Response, 1: ?ConnectionException}
     */
    public function request(callable $send): array
    {
        $response = null;
        $error = null;
        foreach ($this->candidates() as $route) {
            $response = null;
            $error = null;
            try {
                $response = $send($route);
            } catch (ConnectionException $e) {
                $error = $e;
            }

            if (! self::isProxyFailure($response, $error)) {
                return [$response, null];
            }
            // Обрыв связи или шлюза — мёртв сразу; таймаут чтения долгого ответа модели — нет (§6 ТЗ).
            if (OpenAIRouteJournal::failureKillsRoute($response, $error)) {
                $this->journal->markDead($route, $response !== null ? 'HTTP '.$response->status().' '.$response->body() : (string) $error?->getMessage());
            }
        }

        return [$response, $error];
    }

    /**
     * Отказал ли сам маршрут. Ответ OpenAI об ошибке — JSON с объектом «error»; всё прочее
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
     * Бесплатная проверка маршрута (§2 ТЗ): чат с пустым списком сообщений — OpenAI отвечает 400 empty_array,
     * токены не тратятся. Результат — в журнал.
     *
     * @return array{ok: bool, ms: ?int, error: ?string}
     */
    public function probe(OpenAIRoute $route): array
    {
        $started = microtime(true);
        $response = null;
        $error = null;
        try {
            $response = $route->http($this->headers())->connectTimeout(5)->timeout(20)
                ->post($route->url.'/v1/chat/completions', ['model' => 'gpt-4o-mini', 'messages' => []]);
        } catch (ConnectionException $e) {
            $error = $e;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $result = OpenAIRouteJournal::classify($response, $error);
        $this->journal->record($route, $result['ok'], $ms, $result['error']);

        return ['ok' => $result['ok'], 'ms' => $ms, 'error' => $result['error']];
    }
}
