<?php

namespace App\Services\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Карточка клиента для шапки заявки: связанные контрагенты со скидками и
 * сводка по истории заявок этого e-mail.
 *
 * Клиент — адрес почты, как и статус «перепродавец»: другие сотрудники той же
 * организации пишут с других адресов, и их заявки сюда не входят.
 *
 * Определения:
 *  • КП получено — к заявке ушло распознанное КП или счёт (счёт — тоже
 *    предложение), либо отправлено наше КП из редактора;
 *  • win rate — выигранные (closed_won / paid) среди ЗАКРЫТЫХ заявок с КП:
 *    открытые ещё не исход, в знаменатель их не берём;
 *  • оплаты — счета со статусом «оплачен» по дате оплаты, частичные — по
 *    сумме частичной оплаты.
 */
class ClientCardService
{
    /** Окно для частоты заявок: считаем по последнему году, а не за всю историю. */
    private const FREQUENCY_WINDOW_DAYS = 365;

    /** Окно суммы оплат. */
    private const PAYMENTS_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ClientDiscountImportService $discounts,
    ) {}

    /**
     * @return array{
     *   organizations: list<array{id: int, name: string, inn: ?string, discount: float, cost_plus: bool, pinned: bool, defunct: bool}>,
     *   stats: array{total: int, first_at: ?Carbon, last_90: int, every_days: ?int, quoted: int, quoted_pct: ?float, won: int, lost: int, open_quoted: int, win_rate: ?float, paid_sum: float, paid_count: int}
     * }
     */
    public function forEmail(string $email, ?int $requestOrganizationId = null): array
    {
        return [
            'organizations' => $this->organizations($email, $requestOrganizationId),
            'stats' => $this->stats($email),
        ];
    }

    /** @return list<array{id: int, name: string, inn: ?string, discount: float, cost_plus: bool, pinned: bool, defunct: bool}> */
    private function organizations(string $email, ?int $requestOrganizationId): array
    {
        $contact = ClientContact::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        $orgs = $contact?->organizations()->get() ?? collect();
        if ($requestOrganizationId !== null && ! $orgs->contains('id', $requestOrganizationId)) {
            $own = Organization::query()->find($requestOrganizationId);
            if ($own) {
                $orgs->prepend($own);
            }
        }
        $pinnedId = $contact?->pinned_organization_id;

        return $orgs->map(fn (Organization $o) => [
            'id' => $o->id,
            'name' => (string) $o->name,
            'inn' => $o->inn,
            'discount' => $this->discounts->discountFor($o),
            'cost_plus' => $o->isCostPlus(),
            'pinned' => $pinnedId !== null && (int) $pinnedId === $o->id,
            'defunct' => $o->isDefunct(),
        ])->values()->all();
    }

    private function stats(string $email): array
    {
        $requests = Request::query()
            ->whereRaw('lower(client_email) = ?', [mb_strtolower(trim($email))])
            ->whereNull('merged_into_id')
            ->get(['id', 'status', 'created_at']);

        $ids = $requests->pluck('id')->all();
        $total = count($ids);
        if ($total === 0) {
            return [
                'total' => 0, 'first_at' => null, 'last_90' => 0, 'every_days' => null,
                'quoted' => 0, 'quoted_pct' => null, 'won' => 0, 'lost' => 0, 'open_quoted' => 0,
                'win_rate' => null, 'paid_sum' => 0.0, 'paid_count' => 0,
            ];
        }

        // Частота — средний интервал между заявками за последний год.
        $recent = $requests->filter(fn (Request $r) => $r->created_at->gte(now()->subDays(self::FREQUENCY_WINDOW_DAYS)))
            ->sortBy('created_at')->values();
        $everyDays = $recent->count() >= 2
            ? (int) max(1, round($recent->first()->created_at->diffInDays($recent->last()->created_at) / ($recent->count() - 1)))
            : null;

        $quotedIds = collect(DB::table('outbound_quotes')->whereIn('request_id', $ids)
            ->whereIn('status', ['matched', 'parsed'])->distinct()->pluck('request_id'))
            ->merge(DB::table('quotations')->whereIn('request_id', $ids)->whereNotNull('sent_at')->distinct()->pluck('request_id'))
            ->map(fn ($v) => (int) $v)->unique()->flip();

        $won = $lost = $openQuoted = 0;
        foreach ($requests as $r) {
            if (! $quotedIds->has($r->id)) {
                continue;
            }
            match (true) {
                in_array($r->status, [RequestStatus::ClosedWon, RequestStatus::Paid], true) => $won++,
                $r->status === RequestStatus::ClosedLost => $lost++,
                default => $openQuoted++,
            };
        }

        $since = now()->subDays(self::PAYMENTS_WINDOW_DAYS);
        $invoices = Invoice::query()->whereIn('request_id', $ids)
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', InvoiceStatus::Paid->value)->where('paid_at', '>=', $since))
                ->orWhere(fn ($p) => $p->where('status', InvoiceStatus::PartiallyPaid->value)->where('partially_paid_at', '>=', $since)))
            ->get(['status', 'amount_snapshot', 'paid_amount']);
        $paidSum = (float) $invoices->sum(fn (Invoice $i) => $i->status === InvoiceStatus::PartiallyPaid
            ? (float) $i->paid_amount
            : (float) ($i->paid_amount ?? $i->amount_snapshot));

        return [
            'total' => $total,
            'first_at' => $requests->min('created_at'),
            'last_90' => $requests->filter(fn (Request $r) => $r->created_at->gte(now()->subDays(90)))->count(),
            'every_days' => $everyDays,
            'quoted' => $quotedIds->count(),
            'quoted_pct' => round($quotedIds->count() * 100 / $total, 0),
            'won' => $won,
            'lost' => $lost,
            'open_quoted' => $openQuoted,
            'win_rate' => ($won + $lost) > 0 ? round($won * 100 / ($won + $lost), 0) : null,
            'paid_sum' => $paidSum,
            'paid_count' => $invoices->count(),
        ];
    }
}
