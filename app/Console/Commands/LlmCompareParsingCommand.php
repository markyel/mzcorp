<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\AI\OpenAIChatService;
use App\Services\RequestItemParsingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Сравнение моделей на разборе позиций заявки (16.11.2026: RouterAI основным каналом — Qwen или Gemini?).
 *
 * Выборка — заявки, по которым уже ушло КП (позиции проверил менеджер), эталон — их активные позиции.
 * Каждое исходное письмо разбирается заново тем же конвейером (текст, вложения, фото), что и в работе,
 * только моделью-кандидатом; в базу ничего не пишется. «openai» — текущие модели напрямую.
 * Кандидат через RouterAI — без переключения на OpenAI: сбой засчитывается сбоем.
 *
 *   php artisan llm:compare-parsing --limit=36 --models=openai,qwen/qwen3.6-27b,google/gemini-3.1-flash-lite
 */
class LlmCompareParsingCommand extends Command
{
    protected $signature = 'llm:compare-parsing
        {--models=openai,qwen/qwen3.6-27b,google/gemini-3.1-flash-lite : варианты через запятую; openai — текущие модели напрямую}
        {--limit=36 : сколько заявок}
        {--days=30 : из заявок за сколько дней}
        {--until= : по какую дату (Y-m-d) — выборка не сдвигается между прогонами; по умолчанию сейчас}
        {--seed=16 : выборка повторяема}';

    protected $description = 'Сравнить модели на разборе позиций заявок (выборка с КП, эталон — позиции заявки), БД не трогать';

    /** ₽ за 1 млн токенов [вход, выход]: RouterAI — по их прайсу, OpenAI — по $ × 81,8. */
    public const RUB_PER_M = [
        'qwen/qwen3.6-27b' => [26.7, 213.6],
        'google/gemini-3.1-flash-lite' => [26.7, 160.2],
        'google/gemini-3.5-flash-lite' => [32.0, 267.0],
        'google/gemini-3.8-flash' => [80.1, 400.5],
        'deepseek/deepseek-v4.1-flash' => [6.0, 23.8],
        'openai/gpt-4o' => [267.0, 1068.0],
        'openai/gpt-4o-mini' => [16.0, 64.1],
        'gpt-4.1' => [163.6, 654.4],
        'gpt-4o' => [204.5, 818.0],
        'gpt-4o-mini' => [12.3, 49.1],
    ];

    public function handle(RequestItemParsingService $parser): int
    {
        $requests = $this->sample();
        if ($requests->isEmpty()) {
            $this->error('Нет заявок для выборки.');

            return self::FAILURE;
        }
        $this->info('Заявок: '.$requests->count());

        $variants = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));
        $gold = $requests->mapWithKeys(fn (Request $r) => [$r->id => RequestItem::query()->where('request_id', $r->id)->where('is_active', true)
            ->get(['parsed_name', 'parsed_article', 'parsed_qty'])->map(fn ($i) => ['name' => (string) $i->parsed_name, 'article' => $i->parsed_article, 'qty' => (float) $i->parsed_qty])->all()]);

        $report = ['generated_at' => now()->toIso8601String(), 'requests' => [], 'summary' => []];
        foreach ($variants as $variant) {
            $this->useVariant($variant);
            OpenAIChatService::$tally = [];
            OpenAIChatService::$errors = [];
            $sum = ['gold' => 0, 'found' => 0, 'matched' => 0, 'qty_ok' => 0, 'article_ok' => 0, 'with_article' => 0, 'seconds' => 0.0, 'failed' => 0];
            foreach ($requests as $n => $r) {
                $message = EmailMessage::withHistory()->with('attachments')->find($r->email_message_id);
                $errorsBefore = count(OpenAIChatService::$errors);
                $t = microtime(true);
                try {
                    $items = $message ? $parser->parseItemsFromInboundMessage($message) : [];
                } catch (\Throwable $e) {
                    $items = [];
                    OpenAIChatService::$errors[] = mb_substr($e->getMessage(), 0, 200);
                }
                $sec = microtime(true) - $t;
                $failed = count(OpenAIChatService::$errors) > $errorsBefore;
                $m = self::match($gold[$r->id], $items);
                $sum['gold'] += count($gold[$r->id]);
                $sum['found'] += count($items);
                $sum['matched'] += $m['matched'];
                $sum['qty_ok'] += $m['qty_ok'];
                $sum['article_ok'] += $m['article_ok'];
                $sum['with_article'] += $m['with_article'];
                $sum['seconds'] += $sec;
                $sum['failed'] += $failed ? 1 : 0;
                $report['requests'][$r->internal_code][$variant] = [
                    'gold' => count($gold[$r->id]), 'found' => count($items), 'matched' => $m['matched'], 'qty_ok' => $m['qty_ok'],
                    'seconds' => round($sec, 1), 'failed' => $failed,
                    'missed' => $m['missed'], 'extra' => $m['extra'],
                    // Что модель вернула — для разбора ошибок и правки промпта.
                    'items' => array_map(fn ($i) => trim(($i['name'] ?? '').' ['.($i['article'] ?? '').'] x'.($i['qty'] ?? '?').(empty($i['note']) ? '' : ' {'.$i['note'].'}')), $items),
                ];
                $this->line(sprintf('  [%s] %d/%d %s: эталон %d, найдено %d, совпало %d, кол-во верно %d, %.1f с%s',
                    $variant, $n + 1, $requests->count(), $r->internal_code, count($gold[$r->id]), count($items), $m['matched'], $m['qty_ok'], $sec, $failed ? ' · СБОЙ' : ''));
            }
            $cost = 0.0;
            foreach (OpenAIChatService::$tally as $model => $t) {
                $price = self::RUB_PER_M[$model] ?? self::RUB_PER_M[preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model)] ?? [0, 0];
                $cost += ($t['prompt'] * $price[0] + $t['completion'] * $price[1]) / 1_000_000;
            }
            $sum['cost_rub'] = round($cost, 2);
            $sum['tokens'] = OpenAIChatService::$tally;
            $sum['errors'] = array_slice(OpenAIChatService::$errors, 0, 10);
            $report['summary'][$variant] = $sum;
        }

        $this->newLine();
        $this->table(['вариант', 'позиций найдено', 'полнота', 'точность', 'кол-во верно', 'артикул верно', 'сбоев', 'сек/заявку', '₽ на выборку'],
            collect($report['summary'])->map(fn ($s, $v) => [
                $v, $s['found'].' / '.$s['gold'],
                $s['gold'] ? round($s['matched'] * 100 / $s['gold']).'%' : '—',
                $s['found'] ? round($s['matched'] * 100 / $s['found']).'%' : '—',
                $s['matched'] ? round($s['qty_ok'] * 100 / $s['matched']).'%' : '—',
                $s['with_article'] ? round($s['article_ok'] * 100 / $s['with_article']).'%' : '—',
                $s['failed'], round($s['seconds'] / max(1, $requests->count()), 1), $s['cost_rub'],
            ])->values()->all());

        $path = storage_path('app/llm-compare/'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('Подробности: '.$path);

        return self::SUCCESS;
    }

    /** Заявки с КП (позиции проверены менеджером) из входящего письма, 1–25 позиций — повторяемая случайная выборка. */
    private function sample()
    {
        return Request::query()
            ->whereNotNull('email_message_id')->whereNull('merged_into_id')
            ->where('created_at', '>=', $this->until()->subDays((int) $this->option('days')))
            ->where('created_at', '<', $this->until())
            ->whereIn('peak_status', ['quoted', 'under_review', 'awaiting_invoice', 'invoiced', 'paid', 'closed_won'])
            ->whereHas('items', fn ($q) => $q->where('is_active', true), '>=', 1)
            ->whereHas('items', fn ($q) => $q->where('is_active', true), '<=', 25)
            ->orderByRaw('md5(id::text || ?)', [(string) $this->option('seed')])
            ->limit((int) $this->option('limit'))
            ->get(['id', 'internal_code', 'email_message_id']);
    }

    private function until(): \Illuminate\Support\Carbon
    {
        return $this->option('until') ? \Illuminate\Support\Carbon::parse((string) $this->option('until'))->endOfDay() : now();
    }

    /** openai — текущие модели напрямую; иначе всё через RouterAI этой моделью, без переключения. */
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

    /**
     * Позиции модели против эталона: сначала по артикулу, затем по названию (коэффициент Дайса по словам ≥ 0,45).
     *
     * @param  list<array{name:string, article:?string, qty:float}>  $gold
     * @param  list<array<string, mixed>>  $items
     * @return array{matched:int, qty_ok:int, article_ok:int, with_article:int, missed:list<string>, extra:list<string>}
     */
    public static function match(array $gold, array $items, ?array &$pairs = null): array
    {
        // Кириллица-двойник латиницы (М00759 и M00759) — один артикул.
        $norm = fn ($s) => strtr(mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', (string) $s)),
            ['А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X']);
        // Слова от 3 букв и номера от 2 цифр: «РЭП 34-22-11» и «РЭП 34-31-11» — разные реле.
        $words = fn ($s) => array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $s)) ?: [], fn ($w) => mb_strlen($w) >= 3 || (preg_match('/\d/', $w) && mb_strlen($w) >= 2))));
        $sim = function ($a, $b) use ($words) {
            $x = $words($a);
            $y = $words($b);

            // Дайс: длинное название с пояснениями не теряет короткий эталон.
            return $x && $y ? 2 * count(array_intersect($x, $y)) / (count($x) + count($y)) : 0.0;
        };

        // Артикулы — набор кодов через «,»/«;»: «LF0940, F02215Z907» и «F02215Z907, LF0940» — одно и то же,
        // общий код — та же позиция.
        $codes = fn ($s) => array_values(array_filter(array_map($norm, preg_split('/[,;]+/u', (string) $s) ?: [])));
        $sameArticle = fn ($a, $b) => array_intersect($codes($a), $codes($b)) !== [];

        $left = array_values($items);
        $pairs = [];
        $out = ['matched' => 0, 'qty_ok' => 0, 'article_ok' => 0, 'with_article' => 0, 'missed' => [], 'extra' => []];
        foreach (array_values($gold) as $gi => $g) {
            $best = null;
            $bestScore = 0.0;
            foreach ($left as $k => $it) {
                $score = $g['article'] && ! empty($it['article']) && $sameArticle($g['article'], $it['article']) ? 2.0 : $sim($g['name'], $it['name'] ?? '');
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$k, $score];
                }
            }
            if ($best === null || $bestScore < 0.45) {
                $out['missed'][] = mb_substr($g['name'], 0, 80);

                continue;
            }
            $it = $left[$best];
            unset($left[$best]);
            $out['matched']++;
            $pairs[] = [$gi, $best];
            $out['qty_ok'] += abs((float) ($it['qty'] ?? 0) - $g['qty']) < 0.001 ? 1 : 0;
            if ($g['article']) {
                $out['with_article']++;
                $out['article_ok'] += ! empty($it['article']) && $sameArticle($g['article'], $it['article']) ? 1 : 0;
            }
        }
        $out['extra'] = array_values(array_map(fn ($it) => mb_substr((string) ($it['name'] ?? ''), 0, 80), $left));

        return $out;
    }
}
