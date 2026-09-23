<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\AutoQuoteSnapshot;
use App\Models\Request;
use App\Services\Quotes\AutoQuoteOfferService;
use PHPUnit\Framework\TestCase;

/**
 * Письмо с авто-КП. Текст уходит клиенту как есть, поэтому проверяем то, за
 * что стыдно: суммы, количества и единицы в том виде, в каком их читают люди.
 */
class AutoQuoteOfferBodyTest extends TestCase
{
    private function service(): AutoQuoteOfferService
    {
        // Сборка письма от почтовых сервисов не зависит — они нужны только
        // для отправки, поэтому здесь не создаём их вовсе.
        return new AutoQuoteOfferService(
            $this->createMock(\App\Services\Mail\EmailDraftService::class),
            $this->createMock(\App\Services\Mail\OutgoingMailSender::class),
        );
    }

    private function snapshot(array $lines, float $total): AutoQuoteSnapshot
    {
        $snapshot = new AutoQuoteSnapshot;
        $snapshot->lines = $lines;
        $snapshot->total = $total;

        return $snapshot;
    }

    public function test_the_letter_carries_every_position_and_the_total(): void
    {
        $request = new Request;
        $request->internal_code = 'M-2026-16937';

        $body = $this->service()->body($request, $this->snapshot([
            ['sku' => 'M00838', 'name' => 'Соединительное звено', 'qty' => 2, 'unit' => 'шт.', 'unit_price' => 1234.5, 'total' => 2469.0],
            ['sku' => 'M05186', 'name' => 'Вкладыш BFK16', 'qty' => 10, 'unit' => 'шт.', 'unit_price' => 100.0, 'total' => 1000.0],
        ], 3469.0));

        $this->assertStringContainsString('1. Соединительное звено (арт. M00838) — 2 шт. × 1 234,50 ₽ = 2 469,00 ₽', $body);
        $this->assertStringContainsString('2. Вкладыш BFK16 (арт. M05186) — 10 шт. × 100,00 ₽ = 1 000,00 ₽', $body);
        $this->assertStringContainsString('Итого: 3 469,00 ₽', $body);
    }

    public function test_a_fractional_quantity_keeps_its_fraction_and_a_whole_one_loses_the_zeroes(): void
    {
        $request = new Request;
        $request->internal_code = 'M-2026-00001';

        $body = $this->service()->body($request, $this->snapshot([
            ['sku' => 'M14262', 'name' => 'Поручень резиновый', 'qty' => 43.56, 'unit' => 'м', 'unit_price' => 2000.0, 'total' => 87120.0],
        ], 87120.0));

        $this->assertStringContainsString('43,56 м', $body);
        $this->assertStringNotContainsString('43,5600', $body);
    }

    public function test_a_position_without_a_catalogue_code_is_still_readable(): void
    {
        $request = new Request;
        $request->internal_code = 'M-2026-00002';

        $body = $this->service()->body($request, $this->snapshot([
            ['sku' => '', 'name' => 'Ремень тяговый', 'qty' => 1, 'unit' => 'шт.', 'unit_price' => 500.0, 'total' => 500.0],
        ], 500.0));

        $this->assertStringContainsString('1. Ремень тяговый — 1 шт.', $body);
        $this->assertStringNotContainsString('(арт. )', $body);
    }

    public function test_the_subject_names_the_request(): void
    {
        $request = new Request;
        $request->internal_code = 'M-2026-16937';

        $this->assertSame(
            'Коммерческое предложение по заявке M-2026-16937',
            $this->service()->subject($request),
        );
    }
}
