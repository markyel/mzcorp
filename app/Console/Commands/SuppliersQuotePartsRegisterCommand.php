<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Тихая регистрация поставщиков в QuoteParts (переезд поставщиков, решения заказчика 06.11.2026):
 * без писем, в режиме «внесистемный — запросы через QuoteParts». Поставщик — организация с ключом
 * qp_key, контакты — все её адреса: сначала те, с кем переписывались, потом из 1С; тема — «Расценка»
 * (первый адрес — ещё и основной контакт).
 *
 * Уже заведённых в QuoteParts не трогает: их контакты могли расставить люди. Адрес, который
 * QuoteParts знает за другой компанией, пропускается. Темп — под лимит API QuoteParts.
 */
class SuppliersQuotePartsRegisterCommand extends Command
{
    protected $signature = 'suppliers:qp-register
        {--limit=0 : сколько поставщиков за прогон (0 — все)}
        {--pace=700 : пауза между запросами, мс}
        {--apply : выполнить}';

    protected $description = 'Тихо завести поставщиков с контактами в QuoteParts (внесистемный режим)';

    public function handle(QuotePartsClient $qp): int
    {
        $apply = (bool) $this->option('apply');
        $pace = max(0, (int) $this->option('pace')) * 1000;
        $existing = collect($qp->allSupplierLinks())->keyBy('external_id');

        $orgs = SupplierOrganization::query()->whereNotNull('qp_key')
            ->whereHas('suppliers', fn ($q) => $q->whereNotNull('email'))
            ->with(['suppliers' => fn ($q) => $q->whereNotNull('email')->orderByRaw("case when source = 'onec' then 1 else 0 end")->orderBy('id')])
            ->orderBy('id')->get()
            // Заведённых не трогаем — кроме заведённых в переписке без части наших адресов
            // (регистрация прошла, контакты — нет): их контакты доводим до наших.
            ->reject(fn ($o) => $existing->has($o->qp_key) && (
                ($existing[$o->qp_key]['status'] ?? null) !== 'mail'
                || count($existing[$o->qp_key]['contacts'] ?? []) >= min(30, $o->suppliers->count())
            ));
        if ((int) $this->option('limit') > 0) {
            $orgs = $orgs->take((int) $this->option('limit'));
        }

        $this->info('Уже в QuoteParts: '.$existing->count().'. К регистрации: '.$orgs->count().', адресов '.$orgs->sum(fn ($o) => $o->suppliers->count()).'.');
        if (! $apply) {
            $this->info('Это отчёт. Запусти с --apply, чтобы выполнить.');

            return self::SUCCESS;
        }

        $done = $failed = $skippedEmails = 0;
        $bar = $this->output->createProgressBar($orgs->count());
        foreach ($orgs as $org) {
            try {
                $skippedEmails += app(\App\Services\QuoteParts\QuotePartsSupplierService::class)->registerOrganization($org, $pace)['skipped'];
                $done++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('suppliers:qp-register failed', ['organization_id' => $org->id, 'key' => $org->qp_key, 'error' => $e->getMessage()]);
                $this->newLine();
                $this->warn('  '.$org->qp_key.' '.$org->name.': '.mb_substr($e->getMessage(), 0, 200));
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Заведено: {$done}, не удалось: {$failed}, адресов пропущено (у другой компании в QuoteParts): {$skippedEmails}.");

        return self::SUCCESS;
    }

}
