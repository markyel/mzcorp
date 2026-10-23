<?php

namespace App\Services\Photos;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Clipping Magic (https://clippingmagic.com/api): автоматическое удаление фона. Загрузка с format=result
 * сразу отдаёт результат — белый фон, JPEG (config services.clippingmagic). Рабочее фото тратит кредит
 * при первом скачивании; test=true — бесплатно, но с водяным знаком сервиса.
 * На 429 — линейная выдержка 5, 10 с (так велит документация; дольше — порция очереди не уложится).
 */
class ClippingMagicClient
{
    private const RETRIES_429 = 2;

    public function isConfigured(): bool
    {
        return (string) config('services.clippingmagic.id') !== '' && (string) config('services.clippingmagic.secret') !== '';
    }

    public function testMode(): bool
    {
        return (bool) config('services.clippingmagic.test');
    }

    /**
     * Очистить фон: файл на входе, на выходе JPEG на белом фоне и id изображения в сервисе.
     *
     * @return array{body: string, id: ?string}
     */
    public function clip(string $absPath, string $filename): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Не задан ключ API Clipping Magic (CLIPPINGMAGIC_API_ID / CLIPPINGMAGIC_API_SECRET).');
        }

        for ($attempt = 1; ; $attempt++) {
            $response = $this->http()
                ->attach('image', fopen($absPath, 'r'), $filename)
                ->post('/images', array_filter([
                    'format' => 'result',
                    'test' => $this->testMode() ? 'true' : null,
                    'background.color' => (string) config('services.clippingmagic.background', '#FFFFFF'),
                    'output.opaqueFileFormat' => 'jpeg',
                    'output.jpegQuality' => (string) config('services.clippingmagic.jpeg_quality', 90),
                ]));

            if ($response->status() === 429 && $attempt <= self::RETRIES_429) {
                sleep(5 * $attempt);

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException($this->errorText($response->status(), $response->json('error.message') ?? $response->body()));
            }

            return ['body' => $response->body(), 'id' => $response->header('x-amz-meta-id') ?: null];
        }
    }

    /**
     * Подписка и остаток кредитов (кэш 5 минут) — показать в разделе.
     *
     * @return array{plan: ?string, state: ?string, credits: ?int}|null
     */
    public function account(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::remember('clippingmagic:account', 300, function () {
            try {
                $r = $this->http()->get('/account');
                if (! $r->successful()) {
                    return null;
                }

                return ['plan' => $r->json('subscriptionPlan'), 'state' => $r->json('subscriptionState'), 'credits' => $r->json('credits')];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.clippingmagic.url'), '/'))
            ->withBasicAuth((string) config('services.clippingmagic.id'), (string) config('services.clippingmagic.secret'))
            ->timeout((int) config('services.clippingmagic.timeout', 35))
            ->acceptJson();
    }

    private function errorText(int $status, mixed $message): string
    {
        $message = mb_substr(trim(strip_tags((string) $message)), 0, 300);

        return match (true) {
            $status === 401 => 'Clipping Magic не принял ключ API (401).',
            $status === 402 || str_contains(mb_strtolower($message), 'credit') => 'В Clipping Magic закончились кредиты: '.$message,
            default => 'Clipping Magic: '.$status.($message !== '' ? ' — '.$message : ''),
        };
    }
}
