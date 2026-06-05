<?php

namespace Tests\Unit\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use App\Services\Mail\EmailTextCleanerService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit тест classifyAttachmentByFilename(): per-attachment маршрутизация
 * парсинга. Без БД — метод от EmailTextCleanerService не зависит.
 *
 * Регресс M-2026-3456: письмо несло и КП («Предложение …»), и счёт
 * («Счет МЗ-5913 …», через «е»). Старый regex `счё?т` не ловил «счет» → счёт
 * штамповался как КП, Invoice не создавался, заявка застревала в «КП отправлено».
 */
class OutboundDocumentDetectorTest extends TestCase
{
    private OutboundDocumentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new OutboundDocumentDetector(
            Mockery::mock(EmailTextCleanerService::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('filenameProvider')]
    public function test_classify_attachment_by_filename(string $filename, ?DetectorType $expected): void
    {
        $this->assertSame($expected, $this->detector->classifyAttachmentByFilename($filename));
    }

    /**
     * @return iterable<string, array{string, ?DetectorType}>
     */
    public static function filenameProvider(): iterable
    {
        // Счёт — обе орфографии (е/ё) и форматы.
        yield 'счет через е (M-2026-3456)' => ['Счет МЗ-5913 от 2026-06-05_09-24-37.pdf', DetectorType::OutboundInvoice];
        yield 'счёт через ё' => ['Счёт на оплату 123.pdf', DetectorType::OutboundInvoice];
        yield 'invoice en' => ['Invoice_2026_001.pdf', DetectorType::OutboundInvoice];

        // КП.
        yield 'предложение' => ['Предложение МЗ-351921 от 2026-06-05_09-25-26.pdf', DetectorType::OutboundQuotationFull];
        yield 'кп' => ['КП 4456.xlsx', DetectorType::OutboundQuotationFull];
        yield 'quotation en' => ['quotation-final.pdf', DetectorType::OutboundQuotationFull];

        // Не самоопределяются — null (наследуют тип письма в MailRouter).
        yield 'спецификация' => ['Спецификация 26-05913.pdf', null];
        yield 'договор со счётом в скобках' => ['Договор поставки (счет 5687).pdf', null];
        yield 'нерелевантное расширение' => ['Счет МЗ-5913.png', null];
        yield 'пустое имя' => ['', null];
    }
}
