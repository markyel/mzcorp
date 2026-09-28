<?php

namespace App\Console\Commands;

use App\Services\Marketing\IndustryNewsFeed;
use Illuminate\Console\Command;

/**
 * Забрать ленту новостей отрасли и сложить новое в industry_news_items.
 * Лента держит только 30 последних новостей, поэтому читаем её несколько раз
 * в сутки — иначе к дайджесту начало недели выпадает. См. routes/console.php.
 */
class MediaNewsFetchCommand extends Command
{
    protected $signature = 'media:news-fetch';

    protected $description = 'Fetch the industry news RSS feed and store new items for the weekly digest';

    public function handle(IndustryNewsFeed $feed): int
    {
        $new = $feed->sync();
        if ($new === null) {
            $this->warn('Лента не прочиталась — подробности в логе.');

            return self::SUCCESS;
        }

        $this->info('Новых новостей: '.$new.'.');

        return self::SUCCESS;
    }
}
