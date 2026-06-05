<?php

namespace App\Services\Iqot;

use App\Enums\IqotPositionStatus;
use App\Enums\IqotSubmissionStatus;
use App\Exceptions\IqotApiException;
use App\Jobs\Iqot\PollIqotSubmissionsJob;
use App\Models\IqotPosition;
use App\Models\IqotSubmission;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Лимитированный запуск IQOT-анализа (крон раз в 2 часа).
 *
 * Алгоритм: обновить пул из проигранных КП → посчитать остаток дневного лимита
 * → отобрать pending-позиции по приоритету (ручные → частые в проигранных КП →
 * старшие) → собрать одну submission и отправить → запланировать опрос.
 */
class IqotDispatchService
{
    public function __construct(
        private IqotPoolService $pool,
        private IqotApiService $api,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatch(): array
    {
        if (! (bool) app_setting('iqot.enabled', config('services.iqot.enabled', false))) {
            return ['skipped' => 'disabled'];
        }
        if (! $this->api->isConfigured()) {
            return ['skipped' => 'not_configured'];
        }

        $this->pool->refreshPoolFromLostQuotes();

        $dailyLimit = (int) app_setting('iqot.daily_limit', config('services.iqot.daily_limit', 50));
        if ($dailyLimit <= 0) {
            return ['skipped' => 'daily_limit_zero'];
        }

        $usedToday = IqotPosition::whereNotNull('last_enqueued_at')
            ->where('last_enqueued_at', '>=', now()->startOfDay())
            ->count();
        $remaining = $dailyLimit - $usedToday;
        if ($remaining <= 0) {
            return ['skipped' => 'daily_limit_reached', 'used_today' => $usedToday];
        }

        // За ОДИН заход отдаём только порцию = дневной лимит / число заходов в день
        // (окно 8–18). Так лимит не тратится сразу, а приоритетные позиции уходят
        // уже с первого утреннего захода. Общий дневной лимит при этом соблюдается
        // (ограничение $remaining). Cap 300 — потолок IQOT на одну submission.
        $runsPerDay = max(1, (int) app_setting('iqot.runs_per_day', config('services.iqot.runs_per_day', 6)));
        $perRun = max(1, (int) ceil($dailyLimit / $runsPerDay));
        $take = min($perRun, $remaining, 300);
        $positions = IqotPosition::with('catalogItem')
            ->where('status', IqotPositionStatus::Pending->value)
            ->whereNull('excluded_at')
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [IqotPosition::SOURCE_MANUAL])
            ->orderByDesc('lost_quote_count')
            ->orderBy('created_at')
            ->limit($take)
            ->get();

        if ($positions->isEmpty()) {
            return ['skipped' => 'empty_pool', 'used_today' => $usedToday, 'remaining' => $remaining];
        }

        // Построить валидные строки; невалидные (пустое название) → failed.
        $batch = [];
        foreach ($positions as $pos) {
            if (! $pos->catalogItem) {
                $this->failPosition($pos, 'no_catalog_item', 'Каталожная позиция не найдена');

                continue;
            }
            $line = $this->pool->buildLine($pos);
            if (trim((string) $line['name']) === '') {
                $this->failPosition($pos, 'empty_name', 'Каталог: пустое название позиции');

                continue;
            }
            $batch[] = ['pos' => $pos, 'line' => $line];
        }

        if ($batch === []) {
            return ['skipped' => 'no_valid_lines'];
        }

        $ids = array_map(fn ($b) => $b['pos']->id, $batch);
        $idem = 'iqot-' . now()->format('Ymd_H') . '-' . substr(sha1(implode(',', $ids)), 0, 16);

        $sub = IqotSubmission::create([
            'idempotency_key' => $idem,
            'client_ref' => 'pool-' . now()->format('Ymd_His'),
            'local_status' => IqotSubmissionStatus::Sending->value,
            'catalog_item_ids' => array_map(fn ($b) => $b['pos']->catalog_item_id, $batch),
        ]);

        try {
            $res = $this->api->createSubmissionFromLines(
                array_map(fn ($b) => $b['line'], $batch),
                $idem,
                $sub->client_ref,
            );
            $body = $res['body'] ?? [];
            $headers = $res['headers'] ?? [];

            $sub->forceFill([
                'submission_id' => is_array($body) ? ($body['submission_id'] ?? null) : null,
                'iqot_status' => is_array($body) ? ($body['status'] ?? null) : null,
                'iqot_stage' => is_array($body) ? ($body['stage'] ?? null) : null,
                'local_status' => IqotSubmissionStatus::fromIqot(is_array($body) ? ($body['status'] ?? null) : null)?->value
                    ?? IqotSubmissionStatus::Accepted->value,
                'payload' => $res['payload'] ?? null,
                'last_status_response' => is_array($body) ? $body : [],
                'next_check_after' => ! empty($headers['x_next_check_after'])
                    ? $this->parseTs($headers['x_next_check_after'])
                    : now()->addMinutes(30),
                'request_id_header' => $headers['x_request_id'] ?? null,
            ])->save();

            foreach ($batch as $b) {
                $pos = $b['pos'];
                $line = $b['line'];
                $pos->forceFill([
                    'iqot_submission_id' => $sub->id,
                    'status' => IqotPositionStatus::Analyzing->value,
                    'last_enqueued_at' => now(),
                    'client_ref' => $line['client_ref'],
                    'payload_name' => mb_substr((string) $line['name'], 0, 500),
                    'payload_oem' => isset($line['article']) ? mb_substr((string) $line['article'], 0, 255) : null,
                    'payload_brand' => isset($line['brand']) ? mb_substr((string) $line['brand'], 0, 255) : null,
                    'error_code' => null,
                    'error_message' => null,
                ])->save();
            }

            if ($sub->submission_id) {
                PollIqotSubmissionsJob::dispatch($sub->id);
            }

            return [
                'submitted' => count($batch),
                'submission_id' => $sub->submission_id,
                'used_today' => $usedToday + count($batch),
            ];
        } catch (IqotApiException $e) {
            $sub->forceFill([
                'local_status' => IqotSubmissionStatus::Failed->value,
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
                'request_id_header' => $e->requestIdHeader,
            ])->save();

            foreach ($batch as $b) {
                $this->failPosition($b['pos'], $e->errorCode ?? 'api_error', mb_substr($e->getMessage(), 0, 500));
            }

            Log::error('IqotDispatchService: submission failed', [
                'submission' => $sub->id,
                'code' => $e->errorCode,
                'http' => $e->httpStatus,
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    private function failPosition(IqotPosition $pos, ?string $code, string $message): void
    {
        $pos->forceFill([
            'status' => IqotPositionStatus::Failed->value,
            'error_code' => $code,
            'error_message' => $message,
        ])->save();
    }

    private function parseTs(string $raw): Carbon
    {
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return now()->addMinutes(30);
        }
    }
}
