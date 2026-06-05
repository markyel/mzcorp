<?php

namespace App\Services\Iqot;

use App\Exceptions\IqotApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Клиент IQOT Public API (v1). Порт из LazyLift, адаптирован под MyLift:
 *  - API-ключ берём из app_setting('iqot.api_key') (fallback config/services.iqot);
 *  - createSubmissionFromLines() — generic (позиции формирует вызывающий код,
 *    M-артикул/sku в payload НЕ попадает: шлём название каталога + OEM).
 *
 * Async-poll-only: submission создаётся POST /submissions, статус/отчёт
 * забираются GET-ами с учётом X-Next-Check-After.
 */
class IqotApiService
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
    ) {
        $this->apiKey = $apiKey ?: (string) app_setting('iqot.api_key', (string) config('services.iqot.api_key', ''));
        $this->baseUrl = rtrim($baseUrl ?: (string) config('services.iqot.base_url', 'https://iqot.ru/api/v1'), '/');
        $this->timeout = $timeout ?: (int) config('services.iqot.timeout', 30);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    protected function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('IQOT API key не настроен (Настройки → IQOT).');
        }

        return Http::withToken($this->apiKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry(2, 1000, function (\Exception $e) {
                if ($e instanceof \Illuminate\Http\Client\RequestException) {
                    return in_array($e->response?->status(), [429, 500, 502, 503], true);
                }

                return false;
            }, throw: false);
    }

    /**
     * GET /ping — проверка ключа.
     */
    public function ping(): array
    {
        return $this->unwrap($this->http()->get('/ping'), 'ping');
    }

    /**
     * GET /account/balance — текущий баланс.
     */
    public function balance(): array
    {
        return $this->unwrap($this->http()->get('/account/balance'), 'balance');
    }

    /**
     * POST /submissions — создать submission из готовых строк.
     *
     * @param  list<array{client_ref?:string, name:string, quantity:float, unit?:string, article?:string, brand?:string, description?:string, client_category?:array}>  $lines
     * @return array{status:int, body:array, headers:array, payload:array}
     */
    public function createSubmissionFromLines(array $lines, string $idempotencyKey, ?string $clientRef = null): array
    {
        if ($lines === []) {
            throw new RuntimeException('Не переданы позиции для отправки в IQOT.');
        }
        if (count($lines) > 300) {
            throw new RuntimeException('IQOT принимает не более 300 позиций за одну submission.');
        }

        $payloadItems = [];
        foreach ($lines as $line) {
            $name = trim((string) ($line['name'] ?? ''));
            $qty = (float) ($line['quantity'] ?? 0);
            if ($name === '' || $qty <= 0) {
                throw new RuntimeException('Позиция невалидна для IQOT: требуется непустое название и quantity > 0.');
            }

            $entry = [
                'name' => mb_substr($name, 0, 500),
                'quantity' => $qty,
                'unit' => mb_substr(trim((string) ($line['unit'] ?? '')) ?: 'шт.', 0, 32),
            ];
            if (! empty($line['client_ref'])) {
                $entry['client_ref'] = mb_substr((string) $line['client_ref'], 0, 128);
            }
            // OEM-код (НЕ M-артикул) — IQOT ищет по нему у конкурентов.
            if (! empty($line['article'])) {
                $entry['article'] = mb_substr((string) $line['article'], 0, 255);
            }
            if (! empty($line['brand'])) {
                $entry['brand'] = mb_substr((string) $line['brand'], 0, 255);
            }
            if (! empty($line['description'])) {
                $entry['description'] = mb_substr((string) $line['description'], 0, 5000);
            }
            if (! empty($line['client_category']) && is_array($line['client_category'])) {
                $entry['client_category'] = $line['client_category'];
            }

            $payloadItems[] = $entry;
        }

        $payload = ['items' => $payloadItems];
        if ($clientRef !== null && $clientRef !== '') {
            $payload['client_ref'] = mb_substr($clientRef, 0, 128);
        }

        $resp = $this->http()
            ->withHeaders(['Idempotency-Key' => mb_substr($idempotencyKey, 0, 128)])
            ->post('/submissions', $payload);

        return [
            'status' => $resp->status(),
            'body' => $this->unwrap($resp, 'createSubmission'),
            'headers' => $this->extractHeaders($resp),
            'payload' => $payload,
        ];
    }

    /**
     * GET /submissions/{id} — текущий статус.
     *
     * @return array{body:array, headers:array}
     */
    public function getSubmission(string $submissionId): array
    {
        $resp = $this->http()->get('/submissions/' . $submissionId);

        return [
            'body' => $this->unwrap($resp, 'getSubmission'),
            'headers' => $this->extractHeaders($resp),
        ];
    }

    /**
     * GET /submissions/{id}/report — итоговый отчёт.
     * null при 409 report_not_ready (ещё ждём офферы).
     */
    public function getReport(string $submissionId): ?array
    {
        $resp = $this->http()->get('/submissions/' . $submissionId . '/report');
        if ($resp->status() === 409) {
            $code = $resp->json('error.code');
            if ($code === 'report_not_ready' || $code === null) {
                return null;
            }
        }

        return $this->unwrap($resp, 'getReport');
    }

    /**
     * POST /submissions/{id}/cancel.
     */
    public function cancelSubmission(string $submissionId, ?string $reason = null): array
    {
        return $this->unwrap(
            $this->http()->post('/submissions/' . $submissionId . '/cancel', [
                'reason' => $reason ?? 'Cancelled by MyLift operator',
            ]),
            'cancelSubmission',
        );
    }

    /**
     * Успех → array, иначе → IqotApiException с кодом и request_id.
     */
    protected function unwrap(Response $resp, string $operation): array
    {
        if ($resp->successful()) {
            return (array) ($resp->json() ?? []);
        }

        $requestId = $resp->header('X-Request-Id') ?: null;
        $errorBody = $resp->json('error') ?? [];
        $code = is_array($errorBody) ? ($errorBody['code'] ?? null) : null;
        $msg = is_array($errorBody) ? ($errorBody['message'] ?? null) : null;
        $details = (is_array($errorBody) && isset($errorBody['details']) && is_array($errorBody['details']))
            ? $errorBody['details']
            : [];

        Log::error('IqotApiService: API error', [
            'operation' => $operation,
            'status' => $resp->status(),
            'code' => $code,
            'message' => $msg,
            'request_id' => $requestId,
            'details' => $details,
        ]);

        throw new IqotApiException(
            message: sprintf(
                'IQOT %s failed: HTTP %d%s%s',
                $operation,
                $resp->status(),
                $code ? ' — ' . $code : '',
                $msg ? ': ' . $msg : '',
            ),
            httpStatus: $resp->status(),
            errorCode: $code,
            requestIdHeader: $requestId,
            details: $details,
        );
    }

    /**
     * @return array{x_next_check_after: ?string, x_status_changed_at: ?string, x_request_id: ?string, retry_after: ?string}
     */
    protected function extractHeaders(Response $resp): array
    {
        return [
            'x_next_check_after' => $resp->header('X-Next-Check-After') ?: null,
            'x_status_changed_at' => $resp->header('X-Status-Changed-At') ?: null,
            'x_request_id' => $resp->header('X-Request-Id') ?: null,
            'retry_after' => $resp->header('Retry-After') ?: null,
        ];
    }
}
