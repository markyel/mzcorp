<?php

namespace App\Console\Commands;

use App\Services\Catalog\IdentificationLearner;
use Illuminate\Console\Command;

/**
 * Справочник опознания учится на письмах-уточнениях менеджеров клиентам (App\Services\Catalog\IdentificationLearner).
 * По расписанию раз в день; первый запуск по всей истории — с --limit побольше.
 *
 *   php artisan identification:learn
 *   php artisan identification:learn --limit=2000
 */
class IdentificationLearnCommand extends Command
{
    protected $signature = 'identification:learn {--limit=200 : сколько новых писем за запуск}';

    protected $description = 'Справочник опознания: вопросы менеджеров клиентам — в подсказки';

    public function handle(IdentificationLearner $learner): int
    {
        $r = $learner->learn(max(1, (int) $this->option('limit')));
        $this->info(sprintf('Писем: %d, вопросов: %d, стали подсказками: %d, новых типов на подтверждение: %d.', $r['letters'], $r['questions'], $r['promoted'], $r['new_types']));

        return self::SUCCESS;
    }
}
