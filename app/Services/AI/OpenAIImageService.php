<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Картинки обложек: основным каналом RouterAI (routerai.ru, services.routerai.image_model, с 24.11.2026 —
 * openai/gpt-image-2), не вышло — тот же запрос напрямую в OpenAI (gpt-image-1.5, services.openai.media_cover.model)
 * через прокси чата. API у обоих одинаковый (images/generations, images/edits, ответ b64_json).
 *
 * generate — картинка по описанию; edit — по образцам: модель перерисовывает
 * то, что на присланных фото (у gpt-image-1.x форму держит input_fidelity=high, gpt-image-2 держит её сам
 * и этот параметр отвергает). editMany — несколько независимых edit параллельно: перерисовка детали идёт
 * 40–60 с, по очереди четыре детали заняли бы четыре минуты.
 */
class OpenAIImageService
{
    /** Картинка по описанию, байты JPEG. */
    public function generate(string $prompt, string $size = '1536x1024', ?string $quality = null): string
    {
        return $this->withFallback('генерация картинки', function (array $t) use ($prompt, $size, $quality) {
            return $t['http']()->post($t['url'].'generations', [
                'model' => $t['model'],
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality ?? (string) config('services.openai.media_cover.quality', 'medium'),
                'output_format' => 'jpeg',
                'n' => 1,
            ]);
        });
    }

    /**
     * Картинка по образцам (байты JPEG/PNG), байты JPEG.
     *
     * @param  list<string>  $images
     */
    public function edit(array $images, string $prompt, string $size = '1536x1024', ?string $quality = null): string
    {
        return $this->withFallback('перерисовка по образцу', function (array $t) use ($images, $prompt, $size, $quality) {
            $req = $t['http']();
            foreach ($images as $i => $bytes) {
                $req = $req->attach('image[]', $bytes, 'ref'.$i.'.'.self::ext($bytes), ['Content-Type' => self::mime($bytes)]);
            }

            return $req->post($t['url'].'edits', $this->editFields($t['model'], $prompt, $size, $quality));
        });
    }

    /**
     * Несколько edit с одним образцом каждый — параллельно. Ключи сохраняются;
     * неудавшиеся — null (решает вызывающий: без одной детали обложка возможна).
     * Не вышло в RouterAI — эти же детали повторно напрямую в OpenAI.
     *
     * @param  array<string, string>  $images  ключ => байты образца
     * @return array<string, ?string>
     */
    public function editMany(array $images, string $prompt, string $size = '1024x1024', ?string $quality = null): array
    {
        if ($images === []) {
            return [];
        }

        $out = array_fill_keys(array_map('strval', array_keys($images)), null);
        $pending = $images;
        foreach ($this->targets() as $t) {
            $responses = Http::pool(function (Pool $pool) use ($pending, $prompt, $size, $quality, $t) {
                foreach ($pending as $key => $bytes) {
                    $t['pool']($pool->as((string) $key))
                        ->attach('image[]', $bytes, 'ref.'.self::ext($bytes), ['Content-Type' => self::mime($bytes)])
                        ->post($t['url'].'edits', $this->editFields($t['model'], $prompt, $size, $quality));
                }
            });
            foreach ($pending as $key => $_) {
                $r = $responses[(string) $key] ?? null;
                try {
                    $out[(string) $key] = $r instanceof Response ? $this->image($r, 'перерисовка детали') : null;
                } catch (\Throwable $e) {
                    Log::warning('OpenAIImageService: part redraw failed', ['via' => $t['name'], 'key' => (string) $key, 'error' => $e->getMessage()]);
                }
            }
            $pending = array_filter($pending, fn ($k) => $out[(string) $k] === null, ARRAY_FILTER_USE_KEY);
            if ($pending === []) {
                break;
            }
        }

        return $out;
    }

    /**
     * Каналы по порядку: RouterAI (если включён и не на паузе предохранителя), затем OpenAI.
     *
     * @return list<array{name: string, url: string, model: string, http: \Closure(): PendingRequest, pool: \Closure(PendingRequest): PendingRequest}>
     */
    private function targets(): array
    {
        $targets = [];
        $routerModel = trim((string) config('services.routerai.image_model', ''));
        if ($routerModel !== '' && app(RouterAiGateway::class)->enabled() && ! RouterAiGateway::isDown()) {
            $headers = ['Authorization' => 'Bearer '.config('services.routerai.api_key')];
            $connect = max(1, (int) config('services.routerai.connect_timeout', 5));
            $targets[] = [
                'name' => 'routerai',
                'url' => rtrim((string) config('services.routerai.base_url', 'https://routerai.ru/api/v1'), '/').'/images/',
                'model' => $routerModel,
                'http' => fn () => Http::withHeaders($headers)->connectTimeout($connect)->timeout($this->timeout()),
                'pool' => fn (PendingRequest $p) => $p->withHeaders($headers)->connectTimeout($connect)->timeout($this->timeout()),
            ];
        }

        // Первый маршрут очереди (OpenAIProxyPool): картинку по маршрутам не перебираем — она долгая и платная.
        $route = app(OpenAIProxyPool::class)->current();
        $targets[] = [
            'name' => 'openai',
            'url' => $route->url.'/v1/images/',
            'model' => (string) config('services.openai.media_cover.model', 'gpt-image-1.5'),
            'http' => fn () => $route->http($this->openAiHeaders())->timeout($this->timeout()),
            'pool' => fn (PendingRequest $p) => $route->apply($p, $this->openAiHeaders())->timeout($this->timeout()),
        ];

        return $targets;
    }

    /** Запрос по каналам: первый ответивший картинкой — результат; все не смогли — последняя ошибка. */
    private function withFallback(string $what, \Closure $send): string
    {
        $last = null;
        foreach ($this->targets() as $t) {
            try {
                return $this->image($send($t), $what);
            } catch (\Throwable $e) {
                $last = $e;
                Log::warning('OpenAIImageService: '.$what.' failed', ['via' => $t['name'], 'model' => $t['model'], 'error' => $e->getMessage()]);
            }
        }

        throw $last ?? new \RuntimeException($what.': нет канала');
    }

    /** @return array<string, string> */
    private function editFields(string $model, string $prompt, string $size, ?string $quality): array
    {
        $fields = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality ?? (string) config('services.openai.media_cover.edit_quality', 'high'),
            'output_format' => 'jpeg',
            'n' => '1',
        ];
        // input_fidelity понимают только gpt-image-1.x; gpt-image-2 на него отвечает 400.
        if (preg_match('/gpt-image-1/', $model) === 1) {
            $fields['input_fidelity'] = 'high';
        }

        return $fields;
    }

    private function image(Response $r, string $what): string
    {
        $b64 = (string) ($r->json('data.0.b64_json') ?? '');
        if (! $r->successful() || $b64 === '') {
            throw new \RuntimeException($what.': '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }

        return (string) base64_decode($b64);
    }

    /** @return array<string, string> */
    private function openAiHeaders(): array
    {
        $headers = ['Authorization' => 'Bearer '.config('services.openai.api_key')];
        if ($pk = (string) config('services.openai.proxy_key')) {
            $headers['X-Proxy-Key'] = $pk;
        }

        return $headers;
    }

    private function timeout(): int
    {
        return (int) config('services.openai.media_cover.timeout', 150);
    }

    private static function mime(string $bytes): string
    {
        return str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
    }

    private static function ext(string $bytes): string
    {
        return self::mime($bytes) === 'image/png' ? 'png' : 'jpg';
    }
}
