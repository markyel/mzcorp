<?php

namespace App\Console\Commands;

use App\Models\SupplierInquiry;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Console\Command;

/**
 * Разовый бэкфилл: проставить `rfq_token` тем запросам поставщикам, у которых
 * маркер [RFQ-…] есть в теме, но колонка пуста.
 *
 * Request-центричная рассылка вставляла токен в тему, но не сохраняла его на
 * инквайри, из-за чего детерминированный матч ответа по токену
 * (SupplierInquiryService::matchInboundByRfqToken) не находил ничего и ответ
 * уходил на запасные пути — вплоть до чужого треда, если поставщик переслал
 * письмо без In-Reply-To. Сама рассылка починена, эта команда лечит историю.
 *
 * Токены уникальны (колонка unique), поэтому коллизии пропускаем с отчётом,
 * а не падаем.
 */
class BackfillRfqTokensCommand extends Command
{
    protected $signature = 'suppliers:backfill-rfq-tokens {--dry-run : Только показать, ничего не писать}';

    protected $description = 'Проставить rfq_token запросам поставщикам, у которых он есть в теме, но не в колонке';

    public function handle(SupplierInquiryService $svc): int
    {
        $dry = (bool) $this->option('dry-run');

        $rows = SupplierInquiry::query()
            ->whereNull('rfq_token')
            ->where('subject', '~*', '\[RFQ-[A-Z0-9]{4,12}\]')
            ->orderBy('id')
            ->get(['id', 'subject']);

        if ($rows->isEmpty()) {
            $this->info('Нечего заполнять.');

            return self::SUCCESS;
        }
        $this->info("Кандидатов: {$rows->count()}".($dry ? ' (сухой прогон)' : ''));

        // Занятые токены: и уже проставленные, и те, что проставим в этом прогоне.
        $taken = SupplierInquiry::query()->whereNotNull('rfq_token')->pluck('rfq_token')
            ->map(fn ($t) => mb_strtoupper((string) $t))->flip();

        $done = 0; $dupes = 0; $noToken = 0;
        foreach ($rows as $r) {
            $token = $svc->extractRfqToken($r->subject);
            if ($token === null) {
                $noToken++;

                continue;
            }
            if ($taken->has($token)) {
                $dupes++;
                $this->warn("  #{$r->id}: токен {$token} уже занят — пропускаю");

                continue;
            }
            $taken[$token] = true;
            if (! $dry) {
                SupplierInquiry::query()->whereKey($r->id)->update(['rfq_token' => $token]);
            }
            $done++;
        }

        $this->info("Проставлено: {$done}, дублей пропущено: {$dupes}, без токена в теме: {$noToken}.");

        return self::SUCCESS;
    }
}
