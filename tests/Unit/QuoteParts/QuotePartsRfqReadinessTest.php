<?php

namespace Tests\Unit\QuoteParts;

use App\Mail\QuotePartsAuthorMail;
use App\Models\QuotePartsRfq;
use App\Services\QuoteParts\QuotePartsRfqReadiness;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Запрос QuoteParts готов к выбору: хотя бы по одной позиции есть из чего выбрать, а остальные
 * позиции уже не ждут (ответили все или вышел срок). Ссылка автору — туда, откуда отправлен запрос.
 */
class QuotePartsRfqReadinessTest extends TestCase
{
    /**
     * @param  array<string, array<string, string>>  $suppliers  поставщик => [код позиции => статус]
     */
    private function rfq(array $suppliers, string $sentAt = '2026-10-20 09:00', ?int $requestId = null): QuotePartsRfq
    {
        $rows = [];
        foreach ($suppliers as $key => $lines) {
            $rows[] = [
                'external_id' => $key,
                'sent' => true,
                'sent_at' => $sentAt,
                'lines' => array_map(fn ($code, $status) => [
                    'code' => $code,
                    'name' => 'Позиция '.$code,
                    'status' => $status,
                    'offers' => $status === 'offered' ? [['id' => 1, 'currency' => 'RUB', 'tiers' => [['min_qty' => 1, 'price' => $key === 's1' ? 1500 : 1200]]]] : [],
                ], array_keys($lines), $lines),
            ];
        }
        $rfq = new QuotePartsRfq(['tag' => '[QP-TEST01]', 'request_id' => $requestId, 'status' => 'open', 'snapshot' => ['suppliers' => $rows]]);
        $rfq->created_at = Carbon::parse($sentAt);

        return $rfq;
    }

    public function test_more_than_half_priced_is_ready_at_once(): void
    {
        $r = app(QuotePartsRfqReadiness::class);
        $now = Carbon::parse('2026-10-20 10:00');

        $this->assertTrue($r->isReady($this->rfq(['s1' => ['M1' => 'offered'], 's2' => ['M1' => 'offered'], 's3' => ['M1' => 'pending']]), $now));
        $this->assertFalse($r->isReady($this->rfq(['s1' => ['M1' => 'offered'], 's2' => ['M1' => 'pending'], 's3' => ['M1' => 'pending']]), $now), 'одна цена из трёх — ждём');
    }

    public function test_all_answered_is_ready_even_with_one_price(): void
    {
        $r = app(QuotePartsRfqReadiness::class);
        $now = Carbon::parse('2026-10-20 10:00');

        $this->assertTrue($r->isReady($this->rfq(['s1' => ['M1' => 'offered'], 's2' => ['M1' => 'declined'], 's3' => ['M1' => 'declined']]), $now));
        $this->assertFalse($r->isReady($this->rfq(['s1' => ['M1' => 'declined'], 's2' => ['M1' => 'declined']]), $now), 'одни отказы — выбирать не из чего');
    }

    public function test_position_still_waiting_holds_the_request_until_the_wait_ends(): void
    {
        $r = app(QuotePartsRfqReadiness::class);
        $rfq = $this->rfq(['s1' => ['M1' => 'offered', 'M2' => 'pending'], 's2' => ['M1' => 'offered', 'M2' => 'pending']]);

        $this->assertFalse($r->isReady($rfq, Carbon::parse('2026-10-20 10:00')), 'по M2 ещё ждём ответов');
        $this->assertTrue($r->isReady($rfq, Carbon::parse('2026-10-23 10:00')), 'два рабочих дня прошли — M2 больше не держит');
    }

    public function test_best_price_of_a_position(): void
    {
        $p = app(QuotePartsRfqReadiness::class)->positions($this->rfq(['s1' => ['M1' => 'offered'], 's2' => ['M1' => 'offered']]));

        $this->assertSame(1200.0, $p['M1']['best']['price']);
        $this->assertCount(2, $p['M1']['offered']);
    }

    public function test_link_leads_where_the_request_was_sent_from(): void
    {
        $this->assertStringEndsWith('/dashboard/requests/20384?tab=suppliers', QuotePartsRfq::authorUrl(20384, '[QP-TEST01]', 'offers'));
        $this->assertStringEndsWith('/dashboard/requests/20384?tab=suppliers', QuotePartsRfq::authorUrl(20384, '[QP-TEST01]', 'question'));
        $this->assertStringContainsString('tab=offers', QuotePartsRfq::authorUrl(null, '[QP-TEST01]', 'offers'));
        $this->assertStringContainsString('pq=QP-TEST01', QuotePartsRfq::authorUrl(null, '[QP-TEST01]', 'offers'));
        $this->assertStringContainsString('tab=questions', QuotePartsRfq::authorUrl(null, '[QP-TEST01]', 'question'));
    }

    public function test_mails_render(): void
    {
        $rfq = $this->rfq(['s1' => ['M1' => 'offered']]);
        $rfq->setRelation('request', null);
        $ready = QuotePartsAuthorMail::ready($rfq, app(QuotePartsRfqReadiness::class)->positions($rfq));
        $html = $ready->render();
        $this->assertStringContainsString('можно выбирать', $html);
        $this->assertStringContainsString('1 500,00 RUB', $html);
        $this->assertStringContainsString('tab=offers', $html);

        $q = QuotePartsAuthorMail::question($rfq, 'OSS', ['Какой тип двери?']);
        $this->assertStringContainsString('Какой тип двери?', $q->render());
        $this->assertStringContainsString('просит уточнить', $q->envelope()->subject);
    }
}
