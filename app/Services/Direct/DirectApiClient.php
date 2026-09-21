<?php

namespace App\Services\Direct;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкий клиент API Яндекс.Директа v5 (JSON).
 *
 * Возвращает разобранный ответ единым видом, без исключений на бизнес-ошибки:
 * Директ отвечает HTTP 200 и кладёт ошибку в тело, поэтому «успех» определяем
 * по наличию `result`, а не по коду ответа.
 *
 * Расход баллов приходит в заголовке Units («потрачено/осталось/суточный лимит»)
 * — сохраняем его в ответе: по нему видно, во что обходится синхронизация.
 */
class DirectApiClient
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, result: array<mixed>|null, error: array{code: int, message: string, detail: string}|null, units: array{spent: int, rest: int, limit: int}|null, http: int}
     */
    public function call(string $service, string $method, array $params = []): array
    {
        $cfg = config('services.yandex_direct');
        $token = (string) ($cfg['token'] ?? '');
        if ($token === '') {
            return $this->fail(0, 'Не задан токен Яндекс.Директа (YANDEX_DIRECT_TOKEN).');
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Accept-Language' => 'ru',
                    'Content-Type' => 'application/json; charset=utf-8',
                ])
                ->withBody(json_encode([
                    'method' => $method,
                    'params' => $params ?: (object) [],
                ], JSON_UNESCAPED_UNICODE), 'application/json')
                ->post(rtrim((string) $cfg['endpoint'], '/').'/'.$service);
        } catch (\Throwable $e) {
            Log::warning('Direct API: запрос не ушёл', [
                'service' => $service, 'method' => $method, 'error' => $e->getMessage(),
            ]);

            return $this->fail(0, 'Сеть: '.$e->getMessage());
        }

        $body = $response->json();
        $units = self::parseUnits($response->header('Units'));

        if (isset($body['error'])) {
            $error = [
                'code' => (int) ($body['error']['error_code'] ?? 0),
                'message' => (string) ($body['error']['error_string'] ?? 'Ошибка Директа'),
                'detail' => (string) ($body['error']['error_detail'] ?? ''),
            ];
            Log::warning('Direct API: ошибка', $error + ['service' => $service, 'method' => $method]);

            return ['ok' => false, 'result' => null, 'error' => $error, 'units' => $units, 'http' => $response->status()];
        }

        return [
            'ok' => isset($body['result']),
            'result' => $body['result'] ?? null,
            'error' => null,
            'units' => $units,
            'http' => $response->status(),
        ];
    }

    /**
     * Заголовок Units приходит как «11/159989/160000» — потрачено на запрос,
     * осталось на сутки, суточный лимит.
     *
     * @return array{spent: int, rest: int, limit: int}|null
     */
    public static function parseUnits(?string $header): ?array
    {
        if ($header === null || ! preg_match('/^(\d+)\/(\d+)\/(\d+)$/', trim($header), $m)) {
            return null;
        }

        return ['spent' => (int) $m[1], 'rest' => (int) $m[2], 'limit' => (int) $m[3]];
    }

    /** @return array{ok: false, result: null, error: array{code: int, message: string, detail: string}, units: null, http: int} */
    private function fail(int $code, string $message): array
    {
        return [
            'ok' => false,
            'result' => null,
            'error' => ['code' => $code, 'message' => $message, 'detail' => ''],
            'units' => null,
            'http' => 0,
        ];
    }
}
