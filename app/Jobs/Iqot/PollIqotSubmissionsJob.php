<?php

namespace App\Jobs\Iqot;

use App\Enums\IqotPositionStatus;
use App\Enums\IqotSubmissionStatus;
use App\Exceptions\IqotApiException;
use App\Models\IqotPosition;
use App\Models\IqotSubmission;
use App\Services\Iqot\IqotApiService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Опрашивает IQOT по submissions, которым пора (или по конкретной записи),
 * и раскладывает готовый отчёт по позициям каталога (iqot_positions) по client_ref.
 * Порт из LazyLift + per-position раскладка.
 */
class PollIqotSubmissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public ?int $submissionId = null)
    {
    }

    public function handle(IqotApiService $svc): void
    {
        if (! $svc->isConfigured()) {
            Log::info('PollIqotSubmissionsJob: IQOT не настроен — пропуск');

            return;
        }

        $query = $this->submissionId !== null
            ? IqotSubmission::query()->whereKey($this->submissionId)->whereNotNull('submission_id')
            : IqotSubmission::query()->needsPolling();

        $submissions = $query->get();
        if ($submissions->isEmpty()) {
            return;
        }

        foreach ($submissions as $sub) {
            try {
                $this->pollOne($sub, $svc);
            } catch (\Throwable $e) {
                Log::error('PollIqotSubmissionsJob: ошибка опроса', [
                    'iqot_submission_id' => $sub->id,
                    'submission_id' => $sub->submission_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function pollOne(IqotSubmission $sub, IqotApiService $svc): void
    {
        if (empty($sub->submission_id)) {
            return;
        }

        try {
            $res = $svc->getSubmission($sub->submission_id);
        } catch (IqotApiException $e) {
            $sub->forceFill([
                'last_polled_at' => now(),
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
                'request_id_header' => $e->requestIdHeader ?? $sub->request_id_header,
            ]);
            if (in_array($e->httpStatus, [401, 403, 404], true)) {
                $sub->local_status = IqotSubmissionStatus::Failed->value;
                $this->failPositions($sub, $e->errorCode, 'IQOT: submission недоступен');
            }
            $sub->save();

            return;
        }

        $body = $res['body'] ?? [];
        $headers = $res['headers'] ?? [];
        $iqotStatus = is_array($body) ? ($body['status'] ?? null) : null;
        $iqotStage = is_array($body) ? ($body['stage'] ?? null) : null;
        $now = now();

        $newLocal = IqotSubmissionStatus::fromIqot(is_string($iqotStatus) ? $iqotStatus : null);
        if ($newLocal && $newLocal->value !== $sub->local_status) {
            $sub->local_status = $newLocal->value;
        }
        if ($iqotStatus && $iqotStatus !== $sub->iqot_status) {
            $sub->iqot_status = $iqotStatus;
        }
        if ($iqotStage && $iqotStage !== $sub->iqot_stage) {
            $sub->iqot_stage = $iqotStage;
        }

        $statusChangedAt = $headers['x_status_changed_at'] ?? ($body['status_changed_at'] ?? null);
        if ($statusChangedAt) {
            try {
                $sub->status_changed_at = Carbon::parse($statusChangedAt);
            } catch (\Throwable) {
            }
        }

        $nextCheckAfter = $headers['x_next_check_after'] ?? null;
        $sub->next_check_after = $nextCheckAfter
            ? $this->safeParse($nextCheckAfter, $now->copy()->addMinutes(30))
            : $now->copy()->addHour();

        if (! empty($headers['x_request_id'])) {
            $sub->request_id_header = $headers['x_request_id'];
        }
        $sub->last_status_response = is_array($body) ? $body : [];
        $sub->last_polled_at = $now;

        // Есть ли готовая line (items[].report_available)?
        $hasReadyItem = false;
        foreach ((is_array($body) ? ($body['items'] ?? []) : []) as $it) {
            if (is_array($it) && ! empty($it['report_available'])) {
                $hasReadyItem = true;
                break;
            }
        }
        if ($hasReadyItem
            && $sub->local_status !== IqotSubmissionStatus::Completed->value
            && $sub->local_status !== IqotSubmissionStatus::ReadyMinimum->value) {
            $sub->local_status = IqotSubmissionStatus::ReadyMinimum->value;
        }

        $isTerminalLocal = in_array($sub->local_status, [
            IqotSubmissionStatus::Completed->value,
            IqotSubmissionStatus::Cancelled->value,
            IqotSubmissionStatus::Failed->value,
        ], true);
        $shouldFetchReport = ! $isTerminalLocal && ($hasReadyItem || $newLocal?->mayHaveReport());
        // completed тоже тянем один раз — финальный снимок.
        if ($sub->local_status === IqotSubmissionStatus::Completed->value && ! $sub->hasReport()) {
            $shouldFetchReport = true;
        }

        if ($shouldFetchReport) {
            try {
                $report = $svc->getReport($sub->submission_id);
                if (is_array($report)) {
                    $sub->report = $report;
                    $sub->report_fetched_at = $now;
                }
            } catch (IqotApiException $e) {
                Log::warning('PollIqotSubmissionsJob: getReport failed', [
                    'iqot_submission_id' => $sub->id,
                    'code' => $e->errorCode,
                    'http' => $e->httpStatus,
                ]);
            }
        }

        $sub->save();

        // Прогресс сбора по позициям (offers_count + iqot item status) — из ответа
        // статуса, ещё до готового отчёта, чтобы было видно движение.
        $this->applyStatusToPositions($sub, is_array($body) ? $body : []);

        if ($sub->hasReport()) {
            $this->applyReportToPositions($sub);
        }
    }

    /**
     * Обновить по позициям live-прогресс из items[] ответа GET /submissions:
     * offers_count и iqot_item_status. Не трогает completed/excluded.
     */
    protected function applyStatusToPositions(IqotSubmission $sub, array $body): void
    {
        $items = $body['items'] ?? [];
        if (! is_array($items)) {
            return;
        }

        foreach (array_values($items) as $i => $it) {
            if (! is_array($it)) {
                continue;
            }
            $pid = $this->resolvePositionId($it, $i, $sub);
            if ($pid === null) {
                continue;
            }
            $pos = IqotPosition::find($pid);
            if (! $pos || (int) $pos->iqot_submission_id !== (int) $sub->id) {
                continue;
            }
            if ($pos->excluded_at !== null || $pos->status === IqotPositionStatus::Completed->value) {
                continue;
            }

            $dirty = false;
            if (isset($it['offers_count']) && is_numeric($it['offers_count'])
                && (int) $pos->report_offers_count !== (int) $it['offers_count']) {
                $pos->report_offers_count = (int) $it['offers_count'];
                $dirty = true;
            }
            $istatus = isset($it['status']) ? mb_substr((string) $it['status'], 0, 32) : null;
            if ($istatus !== null && $pos->iqot_item_status !== $istatus) {
                $pos->iqot_item_status = $istatus;
                $dirty = true;
            }
            if ($dirty) {
                $pos->save();
            }
        }
    }

    /**
     * Разложить отчёт submission по позициям (по client_ref = "pos-{id}").
     */
    protected function applyReportToPositions(IqotSubmission $sub): void
    {
        $report = $sub->report ?? [];
        $entries = $report['items'] ?? $report['lines'] ?? $report['results'] ?? [];
        if (! is_array($entries)) {
            return;
        }

        foreach (array_values($entries) as $i => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pid = $this->resolvePositionId($entry, $i, $sub);
            if ($pid === null) {
                continue;
            }
            $pos = IqotPosition::find($pid);
            if (! $pos || (int) $pos->iqot_submission_id !== (int) $sub->id) {
                continue;
            }
            // Исключена вручную, пока ждали отчёт — не возвращаем в completed.
            if ($pos->excluded_at !== null) {
                continue;
            }

            $pos->forceFill([
                'report' => $entry,
                'report_min_price' => $this->extractMinPrice($entry),
                'report_offers_count' => $this->extractOffersCount($entry),
                'analyzed_at' => now(),
                'status' => IqotPositionStatus::Completed->value,
                'error_code' => null,
                'error_message' => null,
            ])->save();
        }
    }

    /**
     * id позиции по записи ответа IQOT: сначала по client_ref (`pos-{id}`), а
     * если IQOT его не вернул (наблюдалось после dispatch) — по индексу: items[]
     * ответа идут в том же порядке, что и наш payload.items[], где client_ref есть.
     */
    private function resolvePositionId(array $entry, int $index, IqotSubmission $sub): ?int
    {
        if (preg_match('/^pos-(\d+)$/', (string) ($entry['client_ref'] ?? ''), $m)) {
            return (int) $m[1];
        }
        $payloadItems = is_array($sub->payload ?? null) ? ($sub->payload['items'] ?? []) : [];
        $payloadItems = array_values(is_array($payloadItems) ? $payloadItems : []);
        if (isset($payloadItems[$index]['client_ref'])
            && preg_match('/^pos-(\d+)$/', (string) $payloadItems[$index]['client_ref'], $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Мин. цена за единицу по реальной схеме IQOT report-item:
     * best_offer_by_price.price_per_unit, иначе min по all_offers[].price_per_unit
     * (fallback total_price). Старые ключи оставлены как fallback.
     */
    private function extractMinPrice(array $entry): ?float
    {
        $offerPrice = static function ($o): ?float {
            if (! is_array($o)) {
                return null;
            }
            foreach (['price_per_unit', 'total_price', 'price'] as $k) {
                if (isset($o[$k]) && is_numeric($o[$k])) {
                    return (float) $o[$k];
                }
            }

            return null;
        };

        $best = $offerPrice($entry['best_offer_by_price'] ?? null);
        if ($best !== null) {
            return $best;
        }

        $offers = $entry['all_offers'] ?? $entry['offers'] ?? null;
        if (is_array($offers) && $offers !== []) {
            $prices = [];
            foreach ($offers as $o) {
                $p = $offerPrice($o);
                if ($p !== null) {
                    $prices[] = $p;
                }
            }
            if ($prices !== []) {
                return min($prices);
            }
        }

        foreach (['min_price', 'minimum_price', 'price_min', 'best_price', 'lowest_price'] as $k) {
            if (isset($entry[$k]) && is_numeric($entry[$k])) {
                return (float) $entry[$k];
            }
        }

        return null;
    }

    private function extractOffersCount(array $entry): ?int
    {
        foreach (['offers_count', 'offer_count', 'count'] as $k) {
            if (isset($entry[$k]) && is_numeric($entry[$k])) {
                return (int) $entry[$k];
            }
        }
        foreach (['all_offers', 'offers'] as $k) {
            if (isset($entry[$k]) && is_array($entry[$k])) {
                return count($entry[$k]);
            }
        }

        return null;
    }

    private function failPositions(IqotSubmission $sub, ?string $code, string $message): void
    {
        IqotPosition::where('iqot_submission_id', $sub->id)
            ->where('status', IqotPositionStatus::Analyzing->value)
            ->update([
                'status' => IqotPositionStatus::Failed->value,
                'error_code' => $code,
                'error_message' => $message,
                'updated_at' => now(),
            ]);
    }

    private function safeParse(string $raw, Carbon $fallback): Carbon
    {
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
