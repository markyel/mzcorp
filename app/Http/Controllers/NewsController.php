<?php

namespace App\Http\Controllers;

use App\Models\MediaPublication;
use App\Services\Marketing\WeeklyRoundupService;
use Illuminate\Http\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * Публичная лента обзоров недели: архив, страница статьи и RSS для
 * отраслевых порталов. БЕЗ auth — ленту забирают роботы порталов.
 *
 * Черновик видит только вошедший сотрудник (предпросмотр из «Медиаплана»);
 * для всех остальных неопубликованной статьи нет.
 */
class NewsController extends Controller
{
    private const FEED_ITEMS = 20;

    public function index()
    {
        return view('news.index', [
            'items' => $this->published()->orderByDesc('published_at')->paginate(20),
        ]);
    }

    public function show(string $slug)
    {
        $id = (int) $slug;
        $publication = MediaPublication::query()->whereKey($id)->whereNotNull('article')->first();
        abort_if($publication === null, 404);
        abort_if(! $publication->isPublished() && ! auth()->check(), 404);

        // Заголовок поменяли — старая ссылка ведёт на новую, а не в никуда.
        if ($slug !== $publication->newsSlug()) {
            return redirect()->route('news.show', ['slug' => $publication->newsSlug()], 301);
        }

        return view('news.show', [
            'publication' => $publication,
            'article' => $publication->article,
            'preview' => ! $publication->isPublished(),
        ]);
    }

    public function rss(): Response
    {
        $items = $this->published()->orderByDesc('published_at')->limit(self::FEED_ITEMS)->get()
            ->map(fn (MediaPublication $p) => [
                'title' => (string) $p->title,
                'link' => $p->newsUrl(),
                'pubDate' => $p->published_at?->toRfc2822String(),
                'description' => (string) ($p->article['lead'] ?? ''),
                'html' => WeeklyRoundupService::toHtml($p->article),
                'cover' => $p->article['cover'] ?? null,
            ]);

        return response()
            ->view('news.rss', ['items' => $items], 200)
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    /** Опубликованные статьи ленты (у них есть структура article). */
    private function published(): Builder
    {
        return MediaPublication::query()
            ->whereNotNull('article')
            ->where('status', 'published')
            ->whereHas('channel', fn ($q) => $q->where('kind', 'rss'));
    }
}
