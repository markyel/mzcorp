<?php

namespace App\Jobs\Marketing;

use App\Models\MediaPublication;
use App\Services\Marketing\MediaCoverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Обложка поста в фоне: сцена из перерисованных деталей идёт 2–4 минуты —
 * столько браузер кнопку медиаплана не ждёт. Пока задача идёт, карточка
 * поста показывает «Обложка готовится», публикация ждёт (isPending).
 */
class MakeMediaCoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    /** Сколько держится пометка «готовится», если воркер упал на полпути. */
    private const PENDING_TTL = 1200;

    public function __construct(public readonly int $publicationId)
    {
    }

    public static function queueFor(MediaPublication $pub): void
    {
        Cache::put(self::pendingKey($pub->id), true, self::PENDING_TTL);
        Cache::forget(self::errorKey($pub->id));
        self::dispatch($pub->id);
    }

    public static function isPending(int $publicationId): bool
    {
        return (bool) Cache::get(self::pendingKey($publicationId), false);
    }

    /** Почему последняя обложка не получилась (для карточки поста). */
    public static function lastError(int $publicationId): ?string
    {
        return Cache::get(self::errorKey($publicationId));
    }

    public function handle(MediaCoverService $covers): void
    {
        // Очередь на проде переотдаёт задачу через 90 с (retry_after), а
        // обложка рисуется дольше — второй воркер не должен рисовать ту же.
        $lock = Cache::lock('media:cover:'.$this->publicationId, $this->timeout);
        if (! $lock->get()) {
            return;
        }
        try {
            $pub = MediaPublication::with('topic')->find($this->publicationId);
            if ($pub === null || $pub->isPublished()) {
                return;
            }

            $res = $covers->make((string) $pub->title, (string) $pub->body, $pub->topic?->source, $pub->subject_key);
            if (! $res['ok']) {
                Cache::put(self::errorKey($pub->id), $res['message'], self::PENDING_TTL);

                return;
            }

            foreach ($pub->images() as $old) {
                $covers->forget($old);
            }
            // У статьи обзора обложка ещё и в article: og:image, RSS, шапка страницы.
            $article = is_array($pub->article) ? array_merge($pub->article, ['cover' => $res['url']]) : $pub->article;
            $pub->forceFill(['image_urls' => [$res['url']], 'article' => $article])->save();
        } finally {
            Cache::forget(self::pendingKey($this->publicationId));
            $lock->release();
        }
    }

    private static function pendingKey(int $id): string
    {
        return 'media:cover:pending:'.$id;
    }

    private static function errorKey(int $id): string
    {
        return 'media:cover:error:'.$id;
    }
}
