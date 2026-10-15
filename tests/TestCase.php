<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Тесты, которые пересоздают или чистят базу, — только на локальной базе. Локальный .env
     * смотрит в боевую базу (Beget): RefreshDatabase сделал бы там migrate:fresh.
     */
    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));
        $touchesDb = isset($uses[RefreshDatabase::class]) || isset($uses[DatabaseMigrations::class])
            || isset($uses[DatabaseTransactions::class]) || isset($uses[DatabaseTruncation::class]);
        if ($touchesDb) {
            $connection = config('database.connections.'.config('database.default'), []);
            $host = (string) ($connection['host'] ?? '');
            $local = ($connection['driver'] ?? null) === 'sqlite' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
            if (! $local) {
                throw new RuntimeException('Тест с базой данных не запускается на удалённой базе ('.$host.'): нужен локальный DB_HOST или sqlite.');
            }
        }

        return parent::setUpTraits();
    }
}
