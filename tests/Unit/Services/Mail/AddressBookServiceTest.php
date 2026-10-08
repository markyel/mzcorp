<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\AddressBookService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Склейка источников адресной книги без БД: один адрес — одна строка из
 * более приоритетного источника, имена без символов, ломающих строку
 * адресатов «Имя <email>, …».
 */
class AddressBookServiceTest extends TestCase
{
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(AddressBookService::class, $method);

        return $m->invoke(new AddressBookService(), ...$args);
    }

    public function test_dedupe_keeps_first_source_and_fills_missing_fields(): void
    {
        $rows = $this->invokePrivate('dedupe', [
            ['email' => 'ivan@client.ru', 'name' => null, 'org' => null, 'source' => 'recent'],
            ['email' => 'ivan@client.ru', 'name' => 'Иван Петров', 'org' => 'ООО Лифт', 'source' => 'client'],
            ['email' => 'not-an-address', 'name' => 'X', 'org' => null, 'source' => 'supplier'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('recent', $rows[0]['source']);
        $this->assertSame('Иван Петров', $rows[0]['name']);
        $this->assertSame('ООО Лифт', $rows[0]['org']);
    }

    public function test_clean_strips_recipient_separators(): void
    {
        $this->assertSame('Петров Иван ООО Лифт', $this->invokePrivate('clean', ' Петров, Иван; <ООО "Лифт"> '));
        $this->assertNull($this->invokePrivate('clean', ' , ; '));
        $this->assertNull($this->invokePrivate('clean', null));
    }

    public function test_starts_with_matches_address_or_name_word(): void
    {
        $row = ['email' => 'kurzaev@myzip.ru', 'name' => 'Илья Курзаев', 'org' => null, 'source' => 'colleague'];

        $this->assertSame(1, $this->invokePrivate('startsWith', $row, 'kur'));
        $this->assertSame(1, $this->invokePrivate('startsWith', $row, 'кур'));
        $this->assertSame(0, $this->invokePrivate('startsWith', $row, 'zip'));
    }

    public function test_name_equal_to_address_is_dropped(): void
    {
        $rows = $this->invokePrivate('withoutEchoNames', [
            ['email' => 'info@revator.ru', 'name' => 'Info@Revator.ru', 'org' => null, 'source' => 'recent'],
            ['email' => 'ivan@client.ru', 'name' => 'Иван', 'org' => null, 'source' => 'client'],
        ]);

        $this->assertNull($rows[0]['name']);
        $this->assertSame('Иван', $rows[1]['name']);
    }

    public function test_like_escapes_wildcards(): void
    {
        $this->assertSame('%50\\%\\_off%', $this->invokePrivate('like', '50%_off'));
    }
}
