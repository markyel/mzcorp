<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Журнал доступности маршрутов к OpenAI (ТЗ 12.11.2026). Запросы к GPT идут только по живым маршрутам,
 * самый быстрый первым; мёртвый прокси из списка не тормозит работу.
 *
 * Хранится в общем кэше (на проде — база) одним значением, пишется под блокировкой. По маршруту:
 * ok, latency_ms (сглаженное 0,6·старое + 0,4·новое, только успешные замеры), last_ms, checked_at, since
 * (с какого момента в этом состоянии), fails (сбоев подряд), error (до 200 символов, без ключей).
 * Опрос — llm:probe-routes раз в 15 минут; при сбое во время работы маршрут сразу помечается мёртвым
 * (markDead), следующий опрос вернёт его, когда оживёт.
 */
class OpenAIRouteJournal
{
    private const KEY = 'openai:routes:journal';

    private const LOCK = 'openai:routes:journal:lock';

    /** Журнал старше — три пропущенных опроса: очередь по конфигу, как без журнала. */
    public const STALE_MINUTES = 45;

    /** Обрыв связи или шлюза прокси — маршрут мёртв сразу; таймаут чтения долгого ответа модели — нет. */
    private const DEAD_PATTERNS = '/cURL error (6|7|35|52|56):|Failed to connect|Connection refused|Could not resolve|Connection timed out/i';

    /** @return array{probed_at: ?string, routes: array<string, array<string, mixed>>} */
    public function all(): array
    {
        $data = Cache::get(self::KEY);

        return is_array($data) ? $data + ['probed_at' => null, 'routes' => []] : ['probed_at' => null, 'routes' => []];
    }

    /**
     * Очередь маршрутов для запроса (§5 ТЗ): живые по возрастанию сглаженного времени (при равенстве — порядок
     * конфига), за ними новые, которых нет в журнале; мёртвые не используются. Журнал старше 45 минут или
     * живых нет совсем — весь список по конфигу.
     *
     * @param  list<OpenAIRoute>  $routes  в порядке конфига
     * @param  array{probed_at: ?string, routes: array<string, array<string, mixed>>}  $journal
     * @return list<OpenAIRoute>
     */
    public static function order(array $routes, array $journal, Carbon $now): array
    {
        $probed = $journal['probed_at'] ?? null;
        if ($probed === null || Carbon::parse($probed)->lt($now->copy()->subMinutes(self::STALE_MINUTES))) {
            return $routes;
        }
        $entries = (array) ($journal['routes'] ?? []);
        $alive = [];
        $fresh = [];
        foreach ($routes as $i => $route) {
            $e = $entries[$route->key] ?? null;
            if ($e === null) {
                $fresh[] = $route;
            } elseif ($e['ok'] ?? false) {
                $alive[] = [$e['latency_ms'] ?? PHP_INT_MAX, $i, $route];
            }
        }
        if ($alive === []) {
            return $routes;
        }
        usort($alive, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [...array_column($alive, 2), ...$fresh];
    }

    /**
     * Проверка маршрута (§2 ТЗ): живой — 2xx или 4xx с объектом error от самого OpenAI (обычно 400 empty_array,
     * бесплатно); мёртвый — нет связи, 5xx, ошибка самого прокси (error строкой, HTML, 404 прокси) и отказ OpenAI
     * стране выхода прокси.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function classify(?Response $response, ?Throwable $error = null): array
    {
        if ($response === null) {
            return ['ok' => false, 'error' => self::clean((string) ($error?->getMessage() ?? 'нет ответа'))];
        }
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'error' => null];
        }
        $openAi = $response->json('error');
        $fromOpenAi = is_array($openAi) && is_string($openAi['message'] ?? null);
        if ($status >= 400 && $status < 500 && $fromOpenAi && ($openAi['code'] ?? null) !== 'unsupported_country_region_territory') {
            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => self::clean('HTTP '.$status.' '.$response->body())];
    }

    /** Сбой во время работы, после которого маршрут мёртв сразу (§6 ТЗ): обрыв связи или шлюза прокси. */
    public static function failureKillsRoute(?Response $response, ?Throwable $error = null): bool
    {
        if ($response !== null) {
            return in_array($response->status(), [502, 503, 504], true) || (self::classify($response)['ok'] === false && $response->status() < 500);
        }

        return $error !== null && preg_match(self::DEAD_PATTERNS, $error->getMessage()) === 1;
    }

    /** Результат проверки маршрута. */
    public function record(OpenAIRoute $route, bool $ok, ?int $ms, ?string $error): void
    {
        $this->write(function (array $j) use ($route, $ok, $ms, $error) {
            $j['routes'][$route->key] = self::next($j['routes'][$route->key] ?? null, $ok, $ms, $error, now(), $route->key);

            return $j;
        });
    }

    /** Маршрут отказал во время работы — мёртв сразу, не дожидаясь опроса. */
    public function markDead(OpenAIRoute $route, string $error): void
    {
        $this->record($route, false, null, $error);
    }

    /** Опрос прошёл: отметка времени и чистка маршрутов, которых больше нет в конфиге. */
    public function finishProbe(array $keys): void
    {
        $this->write(function (array $j) use ($keys) {
            $j['routes'] = array_intersect_key($j['routes'], array_flip($keys));
            $j['probed_at'] = now()->toIso8601String();

            return $j;
        });
    }

    /**
     * Новая запись маршрута по старой и замеру. Смена состояния — одна строка в лог.
     *
     * @param  array<string, mixed>|null  $old
     * @return array<string, mixed>
     */
    public static function next(?array $old, bool $ok, ?int $ms, ?string $error, Carbon $now, string $key = ''): array
    {
        // Новый маршрут сразу живым — не событие; сразу мёртвым — падение.
        $changed = $old === null ? ! $ok : (bool) ($old['ok'] ?? false) !== $ok;
        $latency = $old['latency_ms'] ?? null;
        if ($ok && $ms !== null) {
            $latency = $latency === null ? $ms : (int) round(0.6 * $latency + 0.4 * $ms);
        }
        if ($changed) {
            $ok ? Log::info('OpenAI route is back', ['route' => $key, 'ms' => $ms])
                : Log::warning('OpenAI route is down', ['route' => $key, 'error' => self::clean((string) $error)]);
        }

        return [
            'ok' => $ok,
            'latency_ms' => $latency,
            'last_ms' => $ms ?? ($old['last_ms'] ?? null),
            'checked_at' => $now->toIso8601String(),
            'since' => $changed || $old === null ? $now->toIso8601String() : ($old['since'] ?? $now->toIso8601String()),
            'fails' => $ok ? 0 : (int) ($old['fails'] ?? 0) + 1,
            'error' => $ok ? null : self::clean((string) $error),
        ];
    }

    /** Текст ошибки без ключей API и прокси, до 200 символов. */
    public static function clean(string $text): string
    {
        $secrets = array_filter([(string) config('services.openai.api_key'), (string) config('services.openai.proxy_key')], fn ($s) => strlen($s) >= 6);
        $text = str_replace($secrets, '***', $text);
        $text = (string) preg_replace(['/sk-[A-Za-z0-9_\-]{6,}/', '#(//)[^/@\s:]+:[^/@\s]+@#', '/Bearer\s+\S+/i'], ['sk-***', '$1***@', 'Bearer ***'], $text);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }

    private function write(callable $change): void
    {
        $apply = function () use ($change) {
            Cache::forever(self::KEY, $change($this->all()));
        };
        try {
            Cache::lock(self::LOCK, 10)->block(5, $apply);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $apply(); // журнал — подсказка, а не учёт: лучше запись без блокировки, чем потерянный сбой
        }
    }
}
