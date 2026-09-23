<?php

namespace App\Console\Commands;

use App\Models\DirectQueryReview;
use App\Services\Direct\DirectNegativeService;
use Illuminate\Console\Command;

/**
 * Разобрать поисковые запросы моделью и — если включён автоматический режим —
 * вычесть уверенно чужие.
 *
 * Правилами такое не отсекается: «поручень» есть и у эскалатора, и у ванной.
 * Поэтому смысл запроса оценивает модель, а решение по умолчанию остаётся за
 * человеком: ошибочное минус-слово выключает живой трафик молча.
 */
class DirectNegativesCommand extends Command
{
    protected $signature = 'direct:negatives
        {--days=30 : За сколько дней брать запросы}
        {--apply : Применить уверенно чужие вердикты, не спрашивая настройку}';

    protected $description = 'Разбор поисковых запросов Директа и минус-фразы';

    public function handle(DirectNegativeService $negatives): int
    {
        $res = $negatives->judge((int) $this->option('days'));
        if ($res['error'] !== null) {
            $this->warn($res['error']);
        }
        $this->info("Разобрано запросов: {$res['judged']}, из них чужих: {$res['foreign']}.");

        $pending = DirectQueryReview::query()->whereNull('decision')->orderByDesc('impressions')->limit(10)->get();
        foreach ($pending as $review) {
            $this->line(sprintf(
                '  %-46s %-12s %s',
                mb_substr($review->query, 0, 46),
                DirectQueryReview::verdictLabel($review->verdict),
                $review->phrase ? '→ минус «'.$review->phrase.'»' : '',
            ));
        }

        if ($this->option('apply') || $negatives->autoEnabled()) {
            $applied = $negatives->applyAuto(null, (bool) $this->option('apply'));
            $this->info("Добавлено минус-фраз: {$applied['applied']}.");
            foreach ($applied['messages'] as $message) {
                $this->line('  '.$message);
            }
        } elseif ($pending->isNotEmpty()) {
            $this->line('Решение за человеком — раздел «Директ», блок «Что приносит показы».');
        }

        return self::SUCCESS;
    }
}
