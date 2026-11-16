<?php

namespace App\Console\Commands;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\OutboundQuote;
use App\Prompts\Mail\CategorizeIncomingPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteParsingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Сравнение моделей на задачах, кроме разбора позиций (16.11.2026: что ещё перевести на дешёвую модель).
 *
 *   category        — категория входящего письма (MailCategoryClassifier): тот же промпт и параметры,
 *                     эталон — сохранённая категория; только письма, решённые моделью (не правилами).
 *   outbound-quote  — разбор нашего КП/счёта с ценами (OutboundQuoteParsingService::parseWithGPT),
 *                     эталон — сохранённые позиции документа: кол-во, цена за единицу, сумма документа.
 *
 * В базу ничего не пишется. «openai» — та же модель напрямую; кандидат — через RouterAI без отката на OpenAI.
 *
 *   php artisan llm:compare-tasks category --limit=60 --until=2026-11-15
 *   php artisan llm:compare-tasks outbound-quote --limit=30 --until=2026-11-15
 */
class LlmCompareTasksCommand extends Command
{
    protected $signature = 'llm:compare-tasks
        {task : category | outbound-quote}
        {--models=openai,google/gemini-3.1-flash-lite : варианты через запятую; openai — текущая модель напрямую}
        {--limit=40 : сколько писем / документов}
        {--days=30 : за сколько дней}
        {--until= : по какую дату (Y-m-d) — выборка не сдвигается между прогонами}
        {--seed=16 : выборка повторяема}';

    protected $description = 'Сравнить модели на классификации писем и разборе КП/счетов (эталон — сохранённое), БД не трогать';

    public function handle(): int
    {
        $task = (string) $this->argument('task');
        if (! in_array($task, ['category', 'outbound-quote'], true)) {
            $this->error('Задача: category или outbound-quote');

            return self::FAILURE;
        }
        $variants = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));

        $report = ['task' => $task, 'generated_at' => now()->toIso8601String(), 'cases' => [], 'summary' => []];
        $rows = [];
        foreach ($variants as $variant) {
            $this->useVariant($variant);
            OpenAIChatService::$tally = [];
            OpenAIChatService::$errors = [];
            $started = microtime(true);
            $sum = $task === 'category' ? $this->runCategory($variant, $report) : $this->runOutboundQuote($variant, $report);
            $sum['seconds_per_case'] = round((microtime(true) - $started) / max(1, $sum['cases']), 1);
            $sum['cost_rub'] = self::cost(OpenAIChatService::$tally);
            $sum['tokens'] = OpenAIChatService::$tally;
            $sum['errors'] = array_slice(OpenAIChatService::$errors, 0, 10);
            $report['summary'][$variant] = $sum;
            $rows[] = $task === 'category'
                ? [$variant, $sum['cases'], self::pct($sum['ok'], $sum['cases']), $sum['failed'], $sum['seconds_per_case'], $sum['cost_rub']]
                : [$variant, $sum['cases'], self::pct($sum['total_ok'], $sum['cases']), $sum['found'].' / '.$sum['gold'], self::pct($sum['matched'], $sum['gold']),
                    self::pct($sum['qty_ok'], $sum['matched']), self::pct($sum['price_ok'], $sum['matched']), $sum['failed'], $sum['seconds_per_case'], $sum['cost_rub']];
        }

        $this->newLine();
        $this->table($task === 'category'
            ? ['вариант', 'писем', 'категория как в базе', 'сбоев', 'сек/письмо', '₽ на выборку']
            : ['вариант', 'документов', 'сумма документа верна', 'строк найдено', 'строки из эталона', 'кол-во верно', 'цена верна', 'сбоев', 'сек/док', '₽ на выборку'], $rows);

        if ($task === 'category') {
            $this->line('Расхождения с базой:');
            foreach ($report['cases'] as $id => $c) {
                $answers = array_map(fn ($v) => $v['category'] ?? '—', $c['variants']);
                if (count(array_unique([...array_values($answers), $c['gold']])) > 1) {
                    $this->line("  #{$id} база {$c['gold']} | ".collect($answers)->map(fn ($a, $v) => "{$v}: {$a}")->implode(' | ').' | '.$c['subject']);
                }
            }
        }

        $path = storage_path('app/llm-compare/'.$task.'-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('Подробности: '.$path);

        return self::SUCCESS;
    }

    /** Категория письма: тот же промпт и параметры, что в MailCategoryClassifier::categorize. */
    private function runCategory(string $variant, array &$report): array
    {
        $prompt = app(CategorizeIncomingPrompt::class);
        $chat = app(OpenAIChatService::class);
        $model = (string) config('services.openai.category_model', 'gpt-4o');
        $sum = ['cases' => 0, 'ok' => 0, 'failed' => 0];
        $messages = $this->categorySample();
        foreach ($messages as $n => $m) {
            $t = microtime(true);
            $answer = null;
            try {
                $response = $chat->chat($prompt->build($m), $model, ['temperature' => 0, 'max_tokens' => 400, 'response_format' => ['type' => 'json_object']]);
                $answer = json_decode((string) ($response['content'] ?? ''), true)['type'] ?? null;
            } catch (\Throwable $e) {
                OpenAIChatService::$errors[] = mb_substr($e->getMessage(), 0, 200);
            }
            $gold = (string) $m->category;
            $sum['cases']++;
            $sum['ok'] += $answer === $gold ? 1 : 0;
            $sum['failed'] += $answer === null ? 1 : 0;
            $report['cases'][$m->id]['gold'] = $gold;
            $report['cases'][$m->id]['subject'] = mb_substr((string) $m->subject, 0, 80);
            $report['cases'][$m->id]['variants'][$variant] = ['category' => $answer, 'seconds' => round(microtime(true) - $t, 1)];
            $this->line(sprintf('  [%s] %d/%d #%d: база %s, модель %s', $variant, $n + 1, $messages->count(), $m->id, $gold, $answer ?? 'СБОЙ'));
        }

        return $sum;
    }

    /** Письма, категорию которых решила модель (правила ставят уверенность 1.0), поровну по категориям. */
    private function categorySample()
    {
        $categories = EmailMessage::query()->where('direction', MailDirection::Inbound->value)->whereNotNull('category')
            ->distinct()->pluck('category');
        $per = max(1, intdiv((int) $this->option('limit'), max(1, $categories->count())));

        return $categories->flatMap(fn ($c) => EmailMessage::withHistory()->with(['attachments', 'mailbox'])
            ->where('direction', MailDirection::Inbound->value)->where('category', $c)
            ->whereNotNull('category_confidence')->where('category_confidence', '<', 1)
            ->whereBetween('categorized_at', [$this->until()->subDays((int) $this->option('days')), $this->until()])
            ->orderByRaw('md5(id::text || ?)', [(string) $this->option('seed')])
            ->limit($per)->get())->values();
    }

    /** КП/счёт: тот же parseWithGPT, что в ParseOutboundQuoteJob. */
    private function runOutboundQuote(string $variant, array &$report): array
    {
        static $content = [];
        $parser = app(OutboundQuoteParsingService::class);
        $sum = ['cases' => 0, 'total_ok' => 0, 'gold' => 0, 'found' => 0, 'matched' => 0, 'qty_ok' => 0, 'price_ok' => 0, 'failed' => 0];
        $quotes = $this->quoteSample();
        foreach ($quotes as $n => $q) {
            $att = $q->attachment;
            $ext = strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION));
            $content[$q->id] ??= $parser->extractContent(Storage::disk($att->disk ?: 'local')->path($att->file_path), $ext, isAbsolute: true);
            $t = microtime(true);
            $parsed = null;
            try {
                $parsed = $parser->parseWithGPT($content[$q->id]['text'] ?? null, $content[$q->id]['images'] ?? [], $q->request, $this->emailBody($q->emailMessage));
            } catch (\Throwable $e) {
                OpenAIChatService::$errors[] = mb_substr($e->getMessage(), 0, 200);
            }
            $gold = $q->items->map(fn ($i) => ['name' => (string) $i->raw_name, 'article' => $i->raw_article, 'qty' => (float) $i->quantity, 'price' => (float) $i->unit_price])->all();
            $items = array_map(fn ($i) => ['name' => (string) ($i['name'] ?? ''), 'article' => $i['article'] ?? null, 'qty' => (float) ($i['quantity'] ?? $i['qty'] ?? 0), 'price' => (float) ($i['unit_price'] ?? 0)], $parsed['items'] ?? []);
            $m = self::matchLines($gold, $items);
            $total = (float) ($parsed['document']['total_amount'] ?? 0);
            $totalOk = $parsed !== null && abs($total - (float) $q->total_amount) <= max(1.0, 0.005 * (float) $q->total_amount);
            $sum['cases']++;
            $sum['total_ok'] += $totalOk ? 1 : 0;
            $sum['gold'] += count($gold);
            $sum['found'] += count($items);
            $sum['matched'] += $m['matched'];
            $sum['qty_ok'] += $m['qty_ok'];
            $sum['price_ok'] += $m['price_ok'];
            $sum['failed'] += $parsed === null ? 1 : 0;
            $report['cases'][$q->id]['gold_total'] = (float) $q->total_amount;
            $report['cases'][$q->id]['variants'][$variant] = ['total' => $total, 'lines' => count($items), 'gold_lines' => count($gold), 'matched' => $m['matched'],
                'qty_ok' => $m['qty_ok'], 'price_ok' => $m['price_ok'], 'wrong' => $m['wrong'], 'seconds' => round(microtime(true) - $t, 1)];
            $this->line(sprintf('  [%s] %d/%d КП #%d: сумма %s (база %s), строк %d/%d, цена верна %d, %.1f с%s', $variant, $n + 1, $quotes->count(), $q->id,
                $total, (float) $q->total_amount, count($items), count($gold), $m['price_ok'], microtime(true) - $t, $parsed === null ? ' · СБОЙ' : ''));
        }

        return $sum;
    }

    private function quoteSample()
    {
        return OutboundQuote::query()->with(['items', 'attachment', 'request', 'emailMessage'])
            ->whereIn('status', [OutboundQuote::STATUS_PARSED, OutboundQuote::STATUS_MATCHED])
            ->where('source', OutboundQuote::SOURCE_ATTACHMENT)->whereNotNull('email_attachment_id')->where('total_amount', '>', 0)
            ->whereBetween('created_at', [$this->until()->subDays((int) $this->option('days')), $this->until()])
            ->whereHas('items', null, '>=', 1)->whereHas('items', null, '<=', 30)
            ->orderByRaw('md5(id::text || ?)', [(string) $this->option('seed')])
            ->limit((int) $this->option('limit'))->get()
            ->filter(fn (OutboundQuote $q) => $q->attachment && $q->request && Storage::disk($q->attachment->disk ?: 'local')->exists($q->attachment->file_path))
            ->values();
    }

    /** Как ParseOutboundQuoteJob::extractEmailBody. */
    private function emailBody(?EmailMessage $message): ?string
    {
        if ($message === null) {
            return null;
        }
        $plain = trim((string) ($message->body_plain ?? ''));
        if ($plain !== '') {
            return $plain;
        }
        $text = preg_replace('/<(br|\/p|\/div|\/tr)\b[^>]*>/i', "\n", (string) ($message->body_html ?? '')) ?? '';
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' ? $text : null;
    }

    /**
     * Строки документа против эталона: по артикулу, иначе по названию (Дайс ≥ 0,45, как в llm:compare-parsing).
     *
     * @return array{matched:int, qty_ok:int, price_ok:int, wrong:list<string>}
     */
    public static function matchLines(array $gold, array $items): array
    {
        $byName = LlmCompareParsingCommand::match(
            array_map(fn ($g) => ['name' => $g['name'], 'article' => $g['article'], 'qty' => $g['qty']], $gold),
            array_map(fn ($i) => ['name' => $i['name'], 'article' => $i['article'], 'qty' => $i['qty']], $items),
            $pairs,
        );
        $out = ['matched' => $byName['matched'], 'qty_ok' => $byName['qty_ok'], 'price_ok' => 0, 'wrong' => []];
        foreach ($pairs as [$g, $k]) {
            $gp = $gold[$g]['price'];
            $ip = $items[$k]['price'];
            if (abs($ip - $gp) <= max(0.01, 0.005 * $gp)) {
                $out['price_ok']++;
            } else {
                $out['wrong'][] = mb_substr($gold[$g]['name'], 0, 60).": цена {$ip} вместо {$gp}";
            }
        }

        return $out;
    }

    private function useVariant(string $variant): void
    {
        if ($variant === 'openai') {
            config(['services.routerai.enabled' => false]);

            return;
        }
        config([
            'services.routerai.enabled' => true,
            'services.routerai.by_caller' => '{}',
            'services.routerai.models' => '{}',
            'services.routerai.default_model' => $variant,
            'services.routerai.no_fallback' => true,
        ]);
    }

    private function until(): Carbon
    {
        return $this->option('until') ? Carbon::parse((string) $this->option('until'))->endOfDay() : now();
    }

    private static function cost(array $tally): float
    {
        $cost = 0.0;
        foreach ($tally as $model => $t) {
            $price = LlmCompareParsingCommand::RUB_PER_M[$model] ?? LlmCompareParsingCommand::RUB_PER_M[preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model)] ?? [0, 0];
            $cost += ($t['prompt'] * $price[0] + $t['completion'] * $price[1]) / 1_000_000;
        }

        return round($cost, 2);
    }

    private static function pct(int $a, int $b): string
    {
        return $b ? round($a * 100 / $b).'%' : '—';
    }
}
