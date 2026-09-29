<?php

namespace Tests\Unit\Services;

use App\Services\RequestItemParsingService;
use Tests\TestCase;

/**
 * Примечание позиции не должно повторять справку нашего каталога по ссылке
 * mylift.ru/?code=… (M-2026-17627: «OEM/модель: 4R09654*A» остановило авто-КП
 * как «уточнение клиента»). Слова самого клиента остаются.
 */
class StorefrontEchoTest extends TestCase
{
    private const LINKED = "### https://mylift.ru/?code=M08156\n"
        .'Товар из нашего каталога: Буфер резиновый D=70мм · Артикул: M08156 · Бренд: Sigma (LG-Otis) · OEM/модель: 4R09654*A';

    public function test_catalog_echo_is_dropped_client_words_stay(): void
    {
        $items = $this->drop([
            ['name' => 'Буфер', 'note' => 'OEM/модель: 4R09654*A'],
            ['name' => 'Буфер', 'note' => 'OEM/модель: 4R09654*A; нужен до пятницы'],
            ['name' => 'Буфер', 'note' => 'на противовесе'],
            ['name' => 'Буфер', 'note' => null],
        ], self::LINKED);

        $this->assertNull($items[0]['note']);
        $this->assertSame('нужен до пятницы', $items[1]['note']);
        $this->assertSame('на противовесе', $items[2]['note']);
        $this->assertNull($items[3]['note']);
    }

    public function test_without_our_storefront_link_notes_are_untouched(): void
    {
        $items = $this->drop([['name' => 'Буфер', 'note' => 'OEM/модель: 4R09654*A']], "### https://example.com/x\nOEM/модель: 4R09654*A");

        $this->assertSame('OEM/модель: 4R09654*A', $items[0]['note']);
    }

    private function drop(array $items, ?string $linked): array
    {
        $svc = (new \ReflectionClass(RequestItemParsingService::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod(RequestItemParsingService::class, 'dropStorefrontEcho'))->invoke($svc, $items, $linked);
    }
}
