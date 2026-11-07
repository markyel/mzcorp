<?php

namespace App\Services\Supplier;

use App\Models\SupplierOrganization;
use App\Models\User;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\SupplierMirrorService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Архив поставщика (07.11.2026): неактуальный поставщик не виден в реестре и не предлагается в
 * запросах цен (подбор по матрице, группы, ручной поиск, мониторинг цен). История запросов и
 * переписка остаются, письма от него по-прежнему узнаются как письма поставщика.
 *
 * Источник правды — QuoteParts: у поставщика со связью там архив ставится через API (QuoteParts
 * перестаёт слать ему запросы и показывать нас в его кабинете), ответ ложится в копию. Поставщик
 * без связи в QuoteParts архивируется только здесь.
 */
class SupplierArchiveService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly SupplierMirrorService $mirror,
    ) {}

    public function archive(SupplierOrganization $org, ?string $reason, ?User $by): SupplierOrganization
    {
        $reason = trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 500) : null;
        $this->push($org, ['archived' => true, 'archived_reason' => $reason]);

        $org->forceFill([
            'archived_at' => $org->archived_at ?? now(),
            'archived_reason' => $reason,
            'archived_by_user_id' => $by?->id,
        ])->save();
        Log::info('Supplier archived', ['organization_id' => $org->id, 'qp_key' => $org->qp_key, 'user_id' => $by?->id, 'reason' => $reason]);

        return $org;
    }

    public function restore(SupplierOrganization $org, ?User $by): SupplierOrganization
    {
        $this->push($org, ['archived' => false]);

        $org->forceFill(['archived_at' => null, 'archived_reason' => null, 'archived_by_user_id' => null])->save();
        Log::info('Supplier restored from archive', ['organization_id' => $org->id, 'qp_key' => $org->qp_key, 'user_id' => $by?->id]);

        return $org;
    }

    /**
     * Несколько поставщиков разом (реестр). QuoteParts может отказать одному — остальные архивируются.
     *
     * @param  list<int>  $organizationIds
     * @return array{done:int, failed:array<string, string>} failed: название => причина
     */
    public function archiveMany(array $organizationIds, ?string $reason, ?User $by): array
    {
        $done = 0;
        $failed = [];
        foreach (SupplierOrganization::query()->whereIn('id', $organizationIds)->notArchived()->get() as $org) {
            try {
                $this->archive($org, $reason, $by);
                $done++;
            } catch (Throwable $e) {
                $failed[(string) $org->name] = $e->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Правка в QuoteParts (если связь там есть) → ответ в копию. QuoteParts не принял — архив
     * здесь не ставится, чтобы копия не разошлась с источником.
     *
     * @param  array<string, mixed>  $payload
     */
    private function push(SupplierOrganization $org, array $payload): void
    {
        if (! $org->inQuoteParts()) {
            return;
        }
        try {
            $link = $this->client->updateSupplier((string) $org->qp_key, $payload);
        } catch (Throwable $e) {
            Log::warning('Supplier archive: QuoteParts refused', ['organization_id' => $org->id, 'qp_key' => $org->qp_key, 'error' => $e->getMessage()]);

            throw new RuntimeException('QuoteParts: '.$e->getMessage(), 0, $e);
        }
        if (! array_key_exists('archived_at', (array) $link)) {
            throw new RuntimeException('QuoteParts пока не знает архива поставщиков — сначала нужна выкладка QuoteParts.');
        }
        $this->mirror->apply((array) $link);
        $org->refresh();
    }
}
