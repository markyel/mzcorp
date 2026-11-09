<?php

namespace App\Jobs\Concerns;

/**
 * Долгая фоновая задача — в отдельную очередь «heavy» со своим воркером и retry_after
 * (config('queue.heavy'), соединение database-heavy). Общие воркеры тогда не заняты по
 * 15–30 минут, а общей очереди не нужен retry_after под самую долгую задачу.
 *
 * Вызывать в конструкторе: не через `public $connection/$queue` на уровне класса —
 * PHP 8 падает на композиции с одноимёнными свойствами трейта Queueable
 * (см. SyncMailboxFolderJob::__construct).
 */
trait OnHeavyQueue
{
    protected function onHeavyQueue(): void
    {
        $this->onConnection((string) config('queue.heavy.connection'));
        $this->onQueue((string) config('queue.heavy.queue'));
    }
}
