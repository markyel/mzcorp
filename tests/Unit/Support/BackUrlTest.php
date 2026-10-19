<?php

namespace Tests\Unit\Support;

use App\Support\BackUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

/** «Назад» — туда, откуда пришли, а не в фиксированный раздел. */
class BackUrlTest extends TestCase
{
    private function open(string $url, ?string $referer): void
    {
        $server = $referer !== null ? ['HTTP_REFERER' => $referer] : [];
        $this->app->instance('request', Request::create($url, 'GET', [], [], [], $server));
        url()->setRequest($this->app['request']);
    }

    public function test_back_to_where_the_card_was_opened_from(): void
    {
        $this->open('https://mzcorp.ru/dashboard/suppliers/registry/7', 'https://mzcorp.ru/dashboard/quoteparts?tab=suppliers');

        $back = BackUrl::from('https://mzcorp.ru/dashboard/suppliers?tab=registry');
        $this->assertSame('https://mzcorp.ru/dashboard/quoteparts?tab=suppliers', $back);
        $this->assertSame('← QuoteParts', BackUrl::label($back, '← Поставщики'));
    }

    public function test_foreign_site_or_no_referer_falls_back(): void
    {
        $this->open('https://mzcorp.ru/dashboard/suppliers/registry/7', 'https://evil.example/x');
        $this->assertSame('https://mzcorp.ru/dashboard/suppliers?tab=registry', BackUrl::from('https://mzcorp.ru/dashboard/suppliers?tab=registry'));

        $this->open('https://mzcorp.ru/dashboard/suppliers/registry/7', 'https://mzcorp.ru/dashboard/suppliers/registry/7?x=1');
        $this->assertSame('https://mzcorp.ru/dashboard/suppliers?tab=registry', BackUrl::from('https://mzcorp.ru/dashboard/suppliers?tab=registry'));
        $this->assertSame('← Поставщики', BackUrl::label('https://mzcorp.ru/dashboard/suppliers?tab=registry', '← Поставщики'));
    }
}
