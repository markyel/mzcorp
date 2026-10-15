<?php

namespace App\Jobs\QuoteParts;

use App\Enums\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\QuotePartsConversationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Поставщик написал в обращении через QuoteParts (вне запросов цен): «колокольчик» автору
 * обращения (начал наш сотрудник), а начатое поставщиком — снабжению (решение 15.10).
 */
class HandleQuotePartsConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $supplier = $this->supplierOf((string) ($this->payload['supplier'] ?? ''));

        $author = null;
        if (preg_match('/^user:(\d+)$/', (string) ($this->payload['crm_author_ref'] ?? ''), $m)) {
            $author = User::query()->whereKey((int) $m[1])->whereNull('archived_at')->first();
        }

        $people = $author !== null
            ? collect([$author])
            : User::role(Role::Procurement->value)->whereNull('archived_at')->get();

        foreach ($people as $user) {
            $user->notify(new QuotePartsConversationNotification($this->payload, $supplier));
        }
    }

    private function supplierOf(string $key): ?Supplier
    {
        if (str_starts_with($key, 'org:')) {
            return Supplier::query()->where('supplier_organization_id', (int) substr($key, 4))->orderBy('id')->first();
        }
        if (str_starts_with($key, 'sup:')) {
            return Supplier::query()->find((int) substr($key, 4));
        }

        return null;
    }
}
