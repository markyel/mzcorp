<?php

namespace App\Jobs\Kb;

use App\Services\Kb\ArticleInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Фоновый ИИ-разбор кодов позиций («что это за код и чей») для отчёта
 * «Не найдено в каталоге». Принимает готовый список {code, name, brand} —
 * результат сохраняется в article_code_insights.
 */
class AnalyzeArticleCodesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  array<int, array{code: string, name?: ?string, brand?: ?string}>  $items
     */
    public function __construct(public array $items)
    {
    }

    public function handle(ArticleInsightService $insights): void
    {
        $saved = $insights->analyzeBatch($this->items);
        Log::info('AnalyzeArticleCodesJob: done', ['requested' => count($this->items), 'saved' => $saved]);
    }
}
