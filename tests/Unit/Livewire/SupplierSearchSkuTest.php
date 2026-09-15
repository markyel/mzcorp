<?php

namespace Tests\Unit\Livewire;

use App\Livewire\Suppliers\Index;
use Tests\TestCase;

/**
 * Разбор M-артикула в поиске раздела «Поставщики» (Index::normalizeSku).
 * Снабженец набирает номер как придётся — с русской «М», с пробелом, с дефисом,
 * — и должен попасть в ту же каталожную позицию. Pure-функция, БД не нужна.
 */
class SupplierSearchSkuTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function terms(): array
    {
        return [
            'латиница как в каталоге' => ['M22456', 'M22456'],
            'нижний регистр' => ['m22456', 'M22456'],
            'кириллическая М с русской раскладки' => ['М22456', 'M22456'],
            'кириллическая м строчная' => ['м22456', 'M22456'],
            'с пробелом' => ['M 22456', 'M22456'],
            'с дефисом' => ['M-22456', 'M22456'],
            'с лишними пробелами по краям' => ['  M22456  ', 'M22456'],
            'ведущие нули сохраняются' => ['м02353', 'M02353'],

            'код заявки — не артикул' => ['M-2026-14464', null],
            'слишком короткий номер' => ['M22', null],
            'название позиции' => ['маслосборник', null],
            'e-mail поставщика' => ['sales@eastelevator.cn', null],
            'пусто' => ['', null],
            'только буква' => ['M', null],
        ];
    }

    /** @dataProvider terms */
    public function test_normalizes_supplier_search_term(string $term, ?string $expected): void
    {
        $this->assertSame($expected, Index::normalizeSku($term));
    }
}
