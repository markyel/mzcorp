<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\QuotePartsRfq;
use App\Models\Request as RequestModel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Запрос поставщикам через QuoteParts — второй канал рядом со старой рассылкой.
 *
 * Позиции — только с M-артикулом (каталог): перед запросом они добавляются в пул и сразу
 * передаются. Поставщики — любые из справочника: не принявшие приглашение получают запрос
 * в режиме переписки. Письмо поставщику QuoteParts отправляет сам (EN + ZH, с ящика MyZip на платформе); ответы — формой или
 * письмом — возвращаются сюда снимком (refresh) и показываются в заявке.
 */
class QuotePartsRfqService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly QuotePartsPoolService $pool,
        private readonly QuotePartsSupplierService $suppliers,
    ) {}

    /**
     * @param  array<int, float>  $quantities  catalog_item_id => количество
     * @param  list<int>  $supplierIds
     */
    public function send(?RequestModel $request, array $quantities, array $supplierIds, ?string $replyBy, ?string $message, User $by, bool $mirror = false): QuotePartsRfq
    {
        if (! QuotePartsPilot::enabledFor($by)) {
            throw new RuntimeException('QuoteParts выключен или вы не участник пилота.');
        }

        $catalog = CatalogItem::query()->whereIn('id', array_keys($quantities))->get(['id', 'sku'])->keyBy('id');
        // Любой поставщик из справочника (решение 15.10): кто ещё не в QuoteParts — заводится
        // в режиме переписки, без письма-приглашения. Поставщик в QuoteParts — организация:
        // несколько её адресов дают одного получателя; кому писать — решает режим рассылки.
        $rows = Supplier::query()->with('organization:id,name')->whereIn('id', $supplierIds)->whereNotNull('email')->get();
        if ($catalog->isEmpty() || $rows->isEmpty()) {
            throw new RuntimeException('Нужны позиции с M-артикулом и хотя бы один поставщик с адресом.');
        }
        foreach ($rows as $row) {
            $this->suppliers->ensureRegistered($row);
        }
        $suppliers = $rows->map->quotepartsKey()->unique()->values();

        $statuses = $this->pool->ensureInPool($catalog->keys()->all(), $by);
        $notReady = $catalog->filter(fn ($c) => ($statuses[$c->id] ?? null) !== 'active');
        if ($notReady->isNotEmpty()) {
            throw new RuntimeException('Позиции не в работе в QuoteParts (на паузе или не переданы): '.$notReady->pluck('sku')->implode(', ').'.');
        }

        $result = $this->client->createRfq([
            'items' => $catalog->map(fn ($c) => ['code' => $c->sku, 'quantity' => max(0.001, (float) $quantities[$c->id]), 'note' => null])->values()->all(),
            'suppliers' => $suppliers->all(),
            'reply_by' => $replyBy,
            'message' => $message ?: null,
            'external_ref' => $request?->internal_code,
            // Режим дублирования: ответы поставщиков — копией автору, ответ на копию уходит поставщику.
            'mirror' => $mirror ? ['email' => $this->mirrorAddress($by), 'name' => $by->name] : null,
        ]);

        if ($by->quoteparts_mirror !== $mirror) {
            $by->forceFill(['quoteparts_mirror' => $mirror])->save();
        }

        return QuotePartsRfq::create([
            'remote_id' => $result['id'],
            'tag' => $result['tag'] ?? null,
            'request_id' => $request?->id,
            'created_by_user_id' => $by->id,
            'reply_by' => $result['reply_by'] ?? $replyBy,
            'status' => 'open',
            'snapshot' => $result,
            'synced_at' => now(),
        ]);
    }

    /**
     * Куда дублировать переписку: личный ящик автора (с него он и ответит), иначе адрес входа.
     */
    public function mirrorAddress(User $user): string
    {
        $personal = \App\Models\Mailbox::query()
            ->where('owner_user_id', $user->id)->where('type', \App\Enums\MailboxType::Personal->value)->where('is_active', true)
            ->orderBy('id')->value('email');

        return (string) ($personal ?: $user->email);
    }

    /**
     * Названия поставщиков для снимков запросов: external_id в QuoteParts — «org:{id}»
     * (организация) или «sup:{id}» (отдельный адрес).
     *
     * @param  iterable<QuotePartsRfq>  $rfqs
     * @return \Illuminate\Support\Collection<string, string>
     */
    public function supplierNames(iterable $rfqs): \Illuminate\Support\Collection
    {
        $keys = collect($rfqs)->flatMap(fn ($r) => collect($r->snapshot['suppliers'] ?? [])->pluck('external_id'))->filter()->unique();
        $orgIds = $keys->filter(fn ($k) => str_starts_with($k, 'org:'))->map(fn ($k) => (int) substr($k, 4));
        $supIds = $keys->filter(fn ($k) => str_starts_with($k, 'sup:'))->map(fn ($k) => (int) substr($k, 4));

        return \App\Models\SupplierOrganization::query()->whereIn('id', $orgIds)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => ['org:'.$id => $n])
            ->merge(Supplier::query()->whereIn('id', $supIds)->get(['id', 'name', 'email'])->mapWithKeys(fn ($s) => ['sup:'.$s->id => $s->name ?: $s->email]));
    }

    public function refresh(QuotePartsRfq $rfq): QuotePartsRfq
    {
        $snapshot = $this->client->rfq($rfq->remote_id);
        $rfq->forceFill([
            'snapshot' => $snapshot,
            'status' => $snapshot['status'] ?? $rfq->status,
            'reply_by' => $snapshot['reply_by'] ?? $rfq->reply_by,
            'synced_at' => now(),
        ])->save();

        return $rfq;
    }

    /**
     * Обновить снимки запросов, по которым ещё могут прийти ответы (открытые и
     * закрытые за последние 30 дней — поздние ответы письмом тоже становятся офферами).
     */
    public function refreshRecent(): int
    {
        $n = 0;
        foreach (QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('synced_at', '>=', now()->subDays(30)))
            ->orderBy('synced_at')->limit(200)->get() as $rfq) {
            try {
                $this->refresh($rfq);
                $n++;
            } catch (Throwable $e) {
                Log::warning('QuoteParts rfq refresh failed', ['rfq' => $rfq->remote_id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
