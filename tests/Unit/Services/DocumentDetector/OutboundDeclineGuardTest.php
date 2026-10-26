<?php

namespace Tests\Unit\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use App\Services\Mail\OutboundDocumentDetectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Автозакрытие «отказом менеджера» (outbound_declined) — только безусловный отказ по всей заявке.
 * M-2026-20979 (26.10.2026): «к сожалению, не предложим такой в сборе / готовы рассматривать поставку
 * по-элементно…?» LLM прочла как отказ 0.9 и закрыла потерей; за 60 дней 15 таких закрытий откатили вручную.
 */
class OutboundDeclineGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> собственный текст менеджера из писем, закрытых по ошибке */
    public static function notADecline(): iterable
    {
        yield 'M-2026-20979 по-элементно + ссылка' => ["к сожалению, не предложим такой в сборе\nготовы рассматривать поставку по-элементно и в в корпусе с 2 стрелками?\nhttps://mylift.ru/?code=M07179&fn=view"];
        yield 'M-2026-20907 если вас устроит' => ['Добрый день крышку отдельно не предложим, если вас устроит готовы выяснить поставку тормоза в сборе...'];
        yield 'M-2026-20893 другой разъём из наличия' => ['нет у нас с таким разъемом! устроит из наличия с другим разъемом https://mylift.ru/?code=M22757&fn=view'];
        yield 'M-2026-20375 СДЭК, Так делаем?' => ['Добрый день, Мы к сожалению со СДЭК не работаем. Предлагаю вам самостоятельно вызвать курьера СДЭК на наш адрес, я счет выставлю без доставки. Так делаем?'];
        yield 'M-2026-15742 только это можем' => ['Нет, только это можем предоставить.'];
    }

    #[DataProvider('notADecline')]
    public function test_a_refusal_with_an_alternative_or_a_question_is_a_followup(string $text): void
    {
        $this->assertNotNull(OutboundDocumentDetector::followupIn($text));
    }

    public function test_a_plain_refusal_has_no_followup(): void
    {
        $this->assertNull(OutboundDocumentDetector::followupIn('Добрый день. К сожалению по вашему запросу мы не сможем предоставить вам предложение.'));
        $this->assertNull(OutboundDocumentDetector::followupIn('Не наша номенклатура, к сожалению.'));
    }

    public function test_a_decline_with_a_followup_becomes_a_clarification_not_a_loss(): void
    {
        $g = OutboundDocumentDetectionService::guardDecline(0.9, 'готовы', 1);

        $this->assertSame(DetectorType::OutboundClarification, $g['type']);
        $this->assertSame(OutboundDocumentDetectionService::FOLLOWUP_CLARIFICATION_CONFIDENCE, $g['confidence']);
        $this->assertSame('followup: готовы', $g['guard']);
    }

    public function test_a_decline_on_a_request_of_several_positions_is_only_suggested(): void
    {
        // «Башмаки не поставим» на заявке из 3 позиций (M-2026-15959): плашка, а не автозакрытие.
        $g = OutboundDocumentDetectionService::guardDecline(0.9, null, 3);

        $this->assertSame(DetectorType::OutboundDeclined, $g['type']);
        $this->assertLessThan(0.85, $g['confidence']);
    }

    public function test_a_plain_refusal_on_one_position_stays_a_decline(): void
    {
        $g = OutboundDocumentDetectionService::guardDecline(0.9, null, 1);

        $this->assertSame(DetectorType::OutboundDeclined, $g['type']);
        $this->assertSame(0.9, $g['confidence']);
        $this->assertNull($g['guard']);
    }
}
