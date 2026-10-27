<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Картинки OpenAI (gpt-image-1.5, services.openai.media_cover.model) через тот же прокси, что и чат.
 *
 * generate — картинка по описанию; edit — по образцам: модель перерисовывает
 * то, что на присланных фото (input_fidelity=high держит форму и детали).
 * editMany — несколько независимых edit параллельно: перерисовка детали идёт
 * 40–60 с, по очереди четыре детали заняли бы четыре минуты.
 */
class OpenAIImageService
{
    /** Картинка по описанию, байты JPEG. */
    public function generate(string $prompt, string $size = '1536x1024', ?string $quality = null): string
    {
        $r = $this->http()->post($this->url('generations'), [
            'model' => $this->model(),
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality ?? (string) config('services.openai.media_cover.quality', 'medium'),
            'output_format' => 'jpeg',
            'n' => 1,
        ]);

        return $this->image($r, 'генерация картинки');
    }

    /**
     * Картинка по образцам (байты JPEG/PNG), байты JPEG.
     *
     * @param  list<string>  $images
     */
    public function edit(array $images, string $prompt, string $size = '1536x1024', ?string $quality = null): string
    {
        $req = $this->http();
        foreach ($images as $i => $bytes) {
            $req = $req->attach('image[]', $bytes, 'ref'.$i.'.'.self::ext($bytes), ['Content-Type' => self::mime($bytes)]);
        }

        return $this->image($req->post($this->url('edits'), $this->editFields($prompt, $size, $quality)), 'перерисовка по образцу');
    }

    /**
     * Несколько edit с одним образцом каждый — параллельно. Ключи сохраняются;
     * неудавшиеся — null (решает вызывающий: без одной детали обложка возможна).
     *
     * @param  array<string, string>  $images  ключ => байты образца
     * @return array<string, ?string>
     */
    public function editMany(array $images, string $prompt, string $size = '1024x1024', ?string $quality = null): array
    {
        if ($images === []) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($images, $prompt, $size, $quality) {
            foreach ($images as $key => $bytes) {
                $pool->as((string) $key)
                    ->withHeaders($this->headers())
                    ->timeout($this->timeout())
                    ->attach('image[]', $bytes, 'ref.'.self::ext($bytes), ['Content-Type' => self::mime($bytes)])
                    ->post($this->url('edits'), $this->editFields($prompt, $size, $quality));
            }
        });

        $out = [];
        foreach ($images as $key => $_) {
            $r = $responses[(string) $key] ?? null;
            try {
                $out[$key] = $r instanceof Response ? $this->image($r, 'перерисовка детали') : null;
            } catch (\Throwable) {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function editFields(string $prompt, string $size, ?string $quality): array
    {
        return [
            'model' => $this->model(),
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality ?? (string) config('services.openai.media_cover.edit_quality', 'high'),
            'input_fidelity' => 'high',
            'output_format' => 'jpeg',
            'n' => '1',
        ];
    }

    private function image(Response $r, string $what): string
    {
        $b64 = (string) ($r->json('data.0.b64_json') ?? '');
        if (! $r->successful() || $b64 === '') {
            throw new \RuntimeException($what.': '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }

        return (string) base64_decode($b64);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders($this->headers())->timeout($this->timeout());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['Authorization' => 'Bearer '.config('services.openai.api_key')];
        if ($pk = (string) config('services.openai.proxy_key')) {
            $headers['X-Proxy-Key'] = $pk;
        }

        return $headers;
    }

    private function url(string $op): string
    {
        return rtrim((string) config('services.openai.base_url'), '/').'/v1/images/'.$op;
    }

    private function model(): string
    {
        return (string) config('services.openai.media_cover.model', 'gpt-image-1.5');
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
