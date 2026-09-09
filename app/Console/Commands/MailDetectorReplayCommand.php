<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\EmailTextCleanerService;
use App\Services\Mail\InvoiceMentionMatcher;
use App\Services\Mail\PostSaleFulfillmentDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Регрессионный реплей детерминированных детекторов на корпусе РЕАЛЬНЫХ писем
 * (read-only, без LLM и без записи в БД). Заменяет ручные скрипты-реплеи,
 * которыми 2026-09-09 проверялись правки перед деплоем.
 *
 * Что считается по каждому письму (snapshot):
 *   category           — сохранённая категория (контекст, не сравнивается)
 *   is_reply           — EmailTextCleanerService::isReply
 *   post_sale          — PostSaleFulfillmentDetector::detect() !== null
 *   delivery_inquiry   — PostSaleFulfillmentDetector::deliveryStatusInquiry
 *   requests_invoice   — PostSaleFulfillmentDetector::requestsInvoiceToPay
 *   mentions_invoice   — InvoiceMentionMatcher::mentions(собственный текст)
 *   wants_new          — PostSaleFulfillmentDetector::wantsNewInvoiceOrOrder
 *   cited_document     — CitedOutboundQuoteRouter::detect()['document_number'] либо null
 *   cited_intent       — invoice_intent из detect()
 *
 * Режимы:
 *   --record   записать текущие значения как эталон (baseline.json)
 *   (default)  сравнить текущие значения с эталоном, вывести расхождения,
 *              exit 1 при расхождениях
 *
 * Корпус: --ids=1,2,3 или --ids=/path/file (по id в строке), либо эталон
 * (при сравнении). --sample=N добавляет случайные входящие за --days дней.
 *
 *   php artisan mail:detector-replay --ids=/tmp/ids.txt --sample=300 --record
 *   php artisan mail:detector-replay
 */
class MailDetectorReplayCommand extends Command
{
    protected $signature = 'mail:detector-replay
        {--ids= : список id через запятую или путь к файлу с id по строке}
        {--sample=0 : добавить N случайных входящих писем за --days дней}
        {--days=30 : окно для --sample}
        {--baseline= : путь к эталону (по умолчанию storage/app/mail-corpus/baseline.json)}
        {--record : записать текущие значения как эталон}
        {--show=25 : сколько расхождений показать подробно}';

    protected $description = 'Реплей детерминированных детекторов почты на корпусе реальных писем и сравнение с эталоном';

    public function handle(
        EmailTextCleanerService $cleaner,
        PostSaleFulfillmentDetector $postSale,
        CitedOutboundQuoteRouter $cited,
    ): int {
        $baselinePath = $this->option('baseline') ?: storage_path('app/mail-corpus/baseline.json');
        $baseline = File::exists($baselinePath) ? (array) json_decode(File::get($baselinePath), true) : [];

        $ids = $this->collectIds($baseline);
        if ($ids === []) {
            $this->error('Корпус пуст: укажите --ids или запишите эталон с --record.');

            return self::INVALID;
        }

        $matcher = new InvoiceMentionMatcher;
        $snapshots = [];
        $bar = $this->output->createProgressBar(count($ids));
        foreach (EmailMessage::query()->whereIn('id', $ids)->with('attachments')->orderBy('id')->cursor() as $m) {
            $snapshots[(string) $m->id] = $this->snapshot($m, $cleaner, $postSale, $cited, $matcher);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        if ($this->option('record')) {
            File::ensureDirectoryExists(dirname($baselinePath));
            File::put($baselinePath, json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info(sprintf('Эталон записан: %d писем → %s', count($snapshots), $baselinePath));

            return self::SUCCESS;
        }

        if ($baseline === []) {
            $this->error("Эталона нет ({$baselinePath}) — сначала --record.");

            return self::INVALID;
        }

        $compared = ['is_reply', 'post_sale', 'delivery_inquiry', 'requests_invoice', 'mentions_invoice', 'wants_new', 'cited_document', 'cited_intent'];
        $diffs = [];
        $missing = 0;
        foreach ($baseline as $id => $expected) {
            $actual = $snapshots[(string) $id] ?? null;
            if ($actual === null) {
                $missing++;

                continue;
            }
            foreach ($compared as $field) {
                if (($expected[$field] ?? null) !== ($actual[$field] ?? null)) {
                    $diffs[$field][] = [$id, $expected[$field] ?? null, $actual[$field] ?? null, $actual['subject'] ?? '', $actual['own'] ?? ''];
                }
            }
        }

        $total = array_sum(array_map('count', $diffs));
        $this->line(sprintf('Писем в эталоне: %d, недоступно: %d, расхождений: %d', count($baseline), $missing, $total));
        $show = (int) $this->option('show');
        foreach ($diffs as $field => $rows) {
            $this->warn(sprintf('— %s: %d', $field, count($rows)));
            foreach (array_slice($rows, 0, $show) as [$id, $exp, $act, $subject, $own]) {
                $this->line(sprintf('  %s  %s → %s | %s', $id, json_encode($exp, JSON_UNESCAPED_UNICODE), json_encode($act, JSON_UNESCAPED_UNICODE), $subject));
                $this->line('      ' . $own);
            }
        }

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<int> */
    private function collectIds(array $baseline): array
    {
        $ids = [];
        $opt = (string) $this->option('ids');
        if ($opt !== '') {
            $raw = is_file($opt) ? file($opt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : explode(',', $opt);
            $ids = array_map('intval', (array) $raw);
        }
        $sample = (int) $this->option('sample');
        if ($sample > 0) {
            $ids = array_merge($ids, EmailMessage::query()
                ->where('direction', 'inbound')->where('is_draft', false)
                ->where('sent_at', '>=', now()->subDays((int) $this->option('days')))
                ->inRandomOrder()->limit($sample)->pluck('id')->map(fn ($v) => (int) $v)->all());
        }
        if ($ids === [] && ! $this->option('record')) {
            $ids = array_map('intval', array_keys($baseline));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @return array<string, mixed> */
    private function snapshot(
        EmailMessage $m,
        EmailTextCleanerService $cleaner,
        PostSaleFulfillmentDetector $postSale,
        CitedOutboundQuoteRouter $cited,
        InvoiceMentionMatcher $matcher,
    ): array {
        $own = $cleaner->clientOwnText($m);
        $isReply = $cleaner->isReply($m);
        $citedResult = null;
        try {
            $citedResult = $cited->detect($m);
        } catch (\Throwable) {
            // read-only реплей: сбой PDF/БД — считаем «нет цитаты»
        }

        return [
            'subject' => mb_substr(preg_replace('/\s+/u', ' ', (string) $m->subject), 0, 70),
            'own' => mb_substr(preg_replace('/\s+/u', ' ', $own), 0, 140),
            'category' => $m->category,
            'is_reply' => $isReply,
            'post_sale' => $postSale->detect($m) !== null,
            'delivery_inquiry' => $postSale->deliveryStatusInquiry($m),
            'requests_invoice' => $postSale->requestsInvoiceToPay($m),
            'mentions_invoice' => $matcher->mentions($own),
            'wants_new' => $postSale->wantsNewInvoiceOrOrder((string) $m->subject, $own, $isReply),
            'cited_document' => $citedResult['document_number'] ?? null,
            'cited_intent' => $citedResult !== null ? (bool) $citedResult['invoice_intent'] : null,
        ];
    }
}
