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
                [$link, $skipped] = $this->register($qp, $org, $pace);
                $skippedEmails += $skipped;
                Supplier::query()->where('supplier_organization_id', $org->id)->update([
                    'quoteparts_link_id' => $link['id'],
                    'quoteparts_status' => $link['status'],
                    'quoteparts_offsystem' => true,
                ]);
                $org->forceFill(['qp_status' => $link['status'], 'qp_payload' => $link, 'qp_synced_at' => now()])->save();
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

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function register(QuotePartsClient $qp, SupplierOrganization $org, int $pace): array
    {
        $contacts = $org->suppliers->values();
        $locale = $contacts->contains('language', 'en') ? 'en' : 'ru';
        $taken = [];

        // Первый адрес, который QuoteParts примет, — контакт регистрации.
        $link = null;
        foreach ($contacts as $first) {
            usleep($pace);
            try {
                $link = $qp->registerSupplier([
                    'external_id' => $org->qp_key,
                    'company_name' => $org->name,
                    'contact' => ['email' => $first->email, 'name' => $first->contact_person ?: null, 'locale' => $first->language === 'en' ? 'en' : $locale],
                ]);
                break;
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'другой компании')) {
                    throw $e;
                }
                $taken[mb_strtolower($first->email)] = true;
            }
        }
        if ($link === null) {
            throw new RuntimeException('все адреса QuoteParts знает за другими компаниями');
        }

        $rows = fn () => $contacts->reject(fn ($s) => isset($taken[mb_strtolower($s->email)]))->values()
            ->map(fn ($s, $i) => array_filter([
                'email' => $s->email,
                'name' => $s->contact_person ?: null,
                'topics' => $i === 0 ? array_values(array_unique([...((array) ($s->contact_topics ?: ['quoting'])), 'general'])) : ((array) ($s->contact_topics ?: ['quoting'])),
                'position' => $i + 1,
                'locale' => $s->language === 'en' ? 'en' : $locale,
                'phone' => $s->phone ? mb_substr((string) $s->phone, 0, 40) : null,
            ], fn ($v) => $v !== null))->take(30)->all();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            usleep($pace);
            try {
                $link = $qp->setContacts($org->qp_key, ['dispatch_mode' => 'primary', 'contacts' => $rows()]);
                break;
            } catch (RuntimeException $e) {
                if (! preg_match('/Адрес (\S+) уже принадлежит другой компании/u', $e->getMessage(), $m)) {
                    throw $e;
                }
                $taken[mb_strtolower($m[1])] = true;
            }
        }

        return [$link, count($taken)];
    }
}
