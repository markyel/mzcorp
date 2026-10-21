<?php

namespace App\Console\Commands;

use App\Services\AI\AiUsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Расход ИИ по дням из счётчиков ai_usage_daily: вызовы, токены, $ по ценам из конфига —
 * по моделям и по тем, кто вызывал. Учёт идёт с 21.10.2026; генерация картинок и проверка
 * тона писем ходят в OpenAI мимо общего сервиса и здесь не видны.
 *
 *   php artisan ai:usage            # сегодня и вчера
 *   php artisan ai:usage --days=7
 */
class AiUsageCommand extends Command
{
    protected $signature = 'ai:usage {--days=2 : сколько последних дней} {--by=source : source | model}';

    protected $description = 'Расход ИИ по дням: вызовы, токены, примерная стоимость';

    public function handle(): int
    {
        $from = now()->subDays(max(1, (int) $this->option('days')) - 1)->toDateString();
        $rows = DB::table('ai_usage_daily')->where('day', '>=', $from)->orderBy('day')->get();
        if ($rows->isEmpty()) {
            $this->info('Данных нет: учёт ведётся с момента выкладки.');

            return self::SUCCESS;
        }

        $by = $this->option('by') === 'model' ? 'model' : 'source';
        foreach ($rows->groupBy('day') as $day => $dayRows) {
            $groups = $dayRows->groupBy($by)->map(function ($g, $name) {
                $cost = $g->sum(fn ($r) => AiUsageMeter::cost($r->model, (int) $r->prompt_tokens, (int) $r->completion_tokens, (int) $r->cached_tokens) ?? 0);

                return [$name, (int) $g->sum('calls'), number_format((int) $g->sum('prompt_tokens'), 0, '', ' '), number_format((int) $g->sum('completion_tokens'), 0, '', ' '),
                    $g->pluck('channel')->unique()->implode('+'), '$'.number_format($cost, 3)];
            })->sortByDesc(fn ($r) => (float) ltrim($r[5], '$'))->values()->all();

            $total = $dayRows->sum(fn ($r) => AiUsageMeter::cost($r->model, (int) $r->prompt_tokens, (int) $r->completion_tokens, (int) $r->cached_tokens) ?? 0);
            $this->line('');
            $this->line("<info>{$day}</info> — вызовов ".$dayRows->sum('calls').', примерно $'.number_format($total, 2));
            $this->table([$by === 'model' ? 'Модель' : 'Кто вызывал', 'Вызовов', 'Токены вход', 'Токены выход', 'Канал', '$'], $groups);
        }

        return self::SUCCESS;
    }
}
