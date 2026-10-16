<?php

namespace App\Jobs\Suppliers;

use App\Models\Request as RequestModel;
use App\Models\User;
use App\Services\Supplier\SupplierDispatchRuns;
use App\Services\Supplier\SupplierDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Рассылка запросов поставщикам по заявке — в фоне. Письмо уходит до минуты, а страница ждёт
 * ответа 60 секунд: рассылка в момент нажатия обрывалась по таймауту, менеджер жал ещё раз и
 * поставщики получали дубли (16.10.2026, заявка M-2026-19834). Ход — в SupplierDispatchRuns.
 *
 * Одна задача — одно письмо одному поставщику, следующая ставится сама: очередь на проде
 * переотдаёт задачу через 90 с (retry_after), и задача на всю рассылку досталась бы второму
 * воркеру. Повторов нет (tries = 1): письмо, ушедшее до сбоя, второй раз не уходит; от дублей
 * страхует и SupplierDispatchService::recentDuplicate.
 */
class SendSupplierDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $supplierIds  кому ещё отправить (первому — эта задача)
     * @param  list<int>  $itemIds
     * @param  list<int>  $reqAttachmentIds
     * @param  array<int, array{path:string,name:string,mime:string,size:int}>  $extraFiles
     * @param  array<string, mixed>  $edits
     */
    public function __construct(
        public string $runId,
        public int $requestId,
        public array $supplierIds,
        public array $itemIds,
        public ?string $note,
        public int $userId,
        public array $reqAttachmentIds = [],
        public array $extraFiles = [],
        public array $edits = [],
    ) {
        $this->onQueue((string) config('services.supplier_dispatch.queue', 'default'));
    }

    public function handle(SupplierDispatchService $dispatcher, SupplierDispatchRuns $runs): void
    {
        $run = $runs->current($this->requestId);
        if ($run === null || ($run['id'] ?? null) !== $this->runId || $this->supplierIds === []) {
            return;
        }
        $request = RequestModel::query()->find($this->requestId);
        $user = User::query()->find($this->userId);
        if ($request === null || $user === null) {
            $runs->update($this->requestId, $this->runId, ['status' => 'failed', 'error' => 'Заявка или пользователь не найдены.', 'finished_at' => now()->toIso8601String()]);

            return;
        }

        // Один поставщик за раз и только одной задачей.
        $supplierId = (int) $this->supplierIds[0];
        $lock = Cache::lock('supplier-dispatch:'.$this->runId.':'.$supplierId, 600);
        if (! $lock->get()) {
            return;
        }
        try {
            $runs->update($this->requestId, $this->runId, ['status' => 'running', 'current' => \App\Models\Supplier::query()->whereKey($supplierId)->value('name')]);
            $result = $dispatcher->dispatch($request, [$supplierId], $this->itemIds, $this->note, $user,
                $this->reqAttachmentIds, $this->extraFiles, $this->edits);
        } finally {
            $lock->release();
        }

        $run = $runs->current($this->requestId) ?? $run;
        $rest = array_slice($this->supplierIds, 1);
        $runs->update($this->requestId, $this->runId, [
            'sent' => (int) ($run['sent'] ?? 0) + $result['sent'],
            'failed' => (int) ($run['failed'] ?? 0) + $result['failed'],
            'skipped' => [...($run['skipped'] ?? []), ...($result['skipped'] ?? [])],
            'current' => null,
        ] + ($rest === [] ? ['status' => 'done', 'finished_at' => now()->toIso8601String()] : []));

        if ($rest !== []) {
            self::dispatch($this->runId, $this->requestId, $rest, $this->itemIds, $this->note, $this->userId, $this->reqAttachmentIds, $this->extraFiles, $this->edits);
        } elseif ((int) ($runs->current($this->requestId)['sent'] ?? 0) > 0) {
            // Рассылка ушла — сохранённый выбор в панели больше не нужен.
            \App\Models\SupplierDispatchDraft::query()->where('request_id', $this->requestId)->where('user_id', $this->userId)->delete();
        }
    }

    public function failed(?Throwable $e): void
    {
        // Очередь переотдала задачу, пока первая попытка ещё шлёт письмо: рассылка идёт.
        if ($e instanceof MaxAttemptsExceededException) {
            return;
        }
        Log::error('SendSupplierDispatchJob failed', ['request_id' => $this->requestId, 'run_id' => $this->runId, 'error' => $e?->getMessage()]);
        app(SupplierDispatchRuns::class)->update($this->requestId, $this->runId, [
            'status' => 'failed', 'current' => null, 'error' => mb_substr((string) $e?->getMessage(), 0, 300), 'finished_at' => now()->toIso8601String(),
        ]);
    }
}
