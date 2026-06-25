<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\Request as RequestModel;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteParsingService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Детерминированная коррекция «двух колонок Кол-во» в исходящих КП/счетах.
 *
 * Кейс M-2026-4755 (quote 3122, поз.13 M24157): КП Liftway имеет две колонки
 * «Кол-во» — «заказано/ожидается» (21) и «к отгрузке» (1). Сумма строки =
 * unit_price × вторая колонка. Vision взял первую → qty=21, total=30122.82
 * (=up×21), Σ строк завышена ровно на up×20=28688.40. autoFixRowArithmetic это
 * НЕ ловит (up×qty==total сходится). Ловит сверка печатной «Суммы» в текстовом
 * слое PDF: reconcileDualQuantityColumns правит qty=1, total=1434.42.
 *
 * Расширяет Laravel TestCase ради config()/Log в сервисе. БД не нужна —
 * приватные методы вызываются через рефлексию на чистых массивах.
 */
class OutboundQuoteDualQuantityTest extends TestCase
{
    private function service(): OutboundQuoteParsingService
    {
        // Сервис в этих тестах LLM не дёргает — мок достаточен.
        return new OutboundQuoteParsingService($this->createMock(OpenAIChatService::class));
    }

    private function request(): RequestModel
    {
        $r = new RequestModel();
        $r->id = 4755; // используется только для логов

        return $r;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function reconcile(array $items, array $document, ?string $text): array
    {
        $m = new ReflectionMethod(OutboundQuoteParsingService::class, 'reconcileDualQuantityColumns');
        $m->setAccessible(true);

        return $m->invoke($this->service(), $items, $document, $text, $this->request());
    }

    private function numberAppears(float $value, string $text): bool
    {
        $m = new ReflectionMethod(OutboundQuoteParsingService::class, 'numberAppearsInText');
        $m->setAccessible(true);

        return (bool) $m->invoke($this->service(), $value, $text);
    }

    public function test_corrects_dual_quantity_canonical_case(): void
    {
        // Поз.A — корректная штучная (Сумма напечатана). Поз.B — завышенная
        // из-за первой колонки Кол-ва (печатная Сумма = up×1).
        $items = [
            ['name' => 'Ролик', 'unit_price' => 1000.0, 'quantity' => 1, 'unit_quantity' => null, 'total' => 1000.0, 'price' => 1000.0],
            ['name' => 'Плата M24157', 'unit_price' => 1434.42, 'quantity' => 21, 'unit_quantity' => null, 'total' => 30122.82, 'price' => 1434.42],
        ];
        $document = ['total_amount' => 2434.42, 'prices_include_vat' => true];
        $text = 'Цена 1 000,00 Сумма 1 000,00 ... Плата M24157 21,00 1,00 шт 1 434,42 Сумма 1 434,42';

        $out = $this->reconcile($items, $document, $text);

        $this->assertSame(1, $out[1]['quantity'], 'Кол-во должно стать «к отгрузке» = 1');
        $this->assertEqualsWithDelta(1434.42, $out[1]['total'], 0.01, 'total = печатная Сумма');
        $this->assertSame(1434.42, $out[1]['price']);
        $this->assertArrayHasKey('_corrections', $out[1]);
        $this->assertSame('dual_quantity_column', $out[1]['_corrections'][0]['reason']);

        // Корректная строка A не тронута.
        $this->assertSame(1, $out[0]['quantity']);
        $this->assertEqualsWithDelta(1000.0, $out[0]['total'], 0.01);

        // Σ сошлась с итогом.
        $sum = array_sum(array_column($out, 'total'));
        $this->assertEqualsWithDelta(2434.42, $sum, 0.01);
    }

    public function test_no_change_when_sum_matches_total(): void
    {
        // Σ строк == итогу → переоценки нет, сеть не вмешивается даже при qty≥2.
        $items = [
            ['name' => 'Втулка', 'unit_price' => 100.0, 'quantity' => 5, 'unit_quantity' => null, 'total' => 500.0, 'price' => 100.0],
        ];
        $document = ['total_amount' => 500.0, 'prices_include_vat' => true];
        $text = 'Втулка 5,00 шт Цена 100,00 Сумма 500,00';

        $out = $this->reconcile($items, $document, $text);

        $this->assertSame(5, $out[0]['quantity'], 'легитимное Кол-во не трогаем');
        $this->assertArrayNotHasKey('_corrections', $out[0]);
    }

    public function test_reverts_when_no_convergence(): void
    {
        // Переоценка есть, но ни одна коррекция не сводит Σ к итогу → полный откат,
        // строки остаются как были (полагаемся на обычный warning).
        $items = [
            ['name' => 'X', 'unit_price' => 333.0, 'quantity' => 7, 'unit_quantity' => null, 'total' => 2331.0, 'price' => 333.0],
        ];
        // Итог несовместим ни с каким up×k (k<7); печатной подходящей Суммы нет.
        $document = ['total_amount' => 1000.0, 'prices_include_vat' => true];
        $text = 'X 7,00 шт Цена 333,00 Сумма 2 331,00 итог 1 000,00';

        $out = $this->reconcile($items, $document, $text);

        $this->assertSame(7, $out[0]['quantity'], 'без сходимости — откат к исходному');
        $this->assertArrayNotHasKey('_corrections', $out[0]);
    }

    public function test_skips_dimensional_items(): void
    {
        // Мерный товар (unit_quantity задан) не трогаем — у него своя арифметика.
        $items = [
            ['name' => 'Трос 10 м', 'unit_price' => 50.0, 'quantity' => 3, 'unit_quantity' => 10.0, 'total' => 1500.0, 'price' => 500.0],
        ];
        $document = ['total_amount' => 500.0, 'prices_include_vat' => true];
        $text = 'Трос 50,00 за метр Сумма 500,00';

        $out = $this->reconcile($items, $document, $text);

        $this->assertSame(3, $out[0]['quantity']);
        $this->assertArrayNotHasKey('_corrections', $out[0]);
    }

    public function test_number_appears_respects_russian_formatting(): void
    {
        $this->assertTrue($this->numberAppears(1434.42, 'Сумма 1 434,42 руб'), 'nbsp/space + comma');
        $this->assertTrue($this->numberAppears(1434.42, 'total 1434.42'), 'plain dot');
        $this->assertTrue($this->numberAppears(1434.42, 'итог 1 434.42'), 'space + dot');
        $this->assertTrue($this->numberAppears(1434.42, "narrow\u{202F}1\u{202F}434,42"), 'narrow nbsp');
        $this->assertTrue($this->numberAppears(500.0, 'Сумма 500,00'));
    }

    public function test_number_does_not_match_as_substring(): void
    {
        // 461.20 не должно «найтись» внутри 11 461,20.
        $this->assertFalse($this->numberAppears(461.20, '11 461,20'));
        // 1434.42 не совпадает с 11 434,42.
        $this->assertFalse($this->numberAppears(1434.42, 'Сумма 11 434,42'));
        // Без десятичной части — не совпадение (требуем «,XX»).
        $this->assertFalse($this->numberAppears(1434.42, 'просто 1 434 штуки'));
    }
}
