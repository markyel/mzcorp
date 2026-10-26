<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\Concerns\AnswersQuotePartsSuppliers;
use App\Models\QuotePartsRfq;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Переписка с поставщиком везде одним диалогом (quote-parts._rfq-dialog, решение 26.10.2026): шапка,
 * открытые вопросы, лента из QuotePartsThread::of — письмо запроса, его вопрос, наш ответ с фото, события.
 * Снимок — как у QP-6ZHY80 (OSS, вопрос «ошибка в базе»); без базы данных.
 */
class QuotePartsRfqDialogRenderTest extends TestCase
{
    public function test_the_dialog_shows_the_questions_and_the_whole_thread_once(): void
    {
        $html = Livewire::test(RfqDialogHost::class)->html();

        $this->assertStringContainsString('OSS Китай', $html);
        $this->assertStringContainsString('6ZHY80', $html);
        // Открытый вопрос группы — один раз, над лентой.
        $this->assertSame(1, substr_count($html, 'Ошибка в базе данных'));
        // Лента: наш запрос, событие, вопрос поставщика, наш ответ с фото.
        $this->assertStringContainsString('кому: Steven', $html);
        $this->assertStringContainsString('Поставщик открыл форму запроса', $html);
        $this->assertStringContainsString('Could you send photo for this?', $html);
        $this->assertStringContainsString('Photo in attach file.', $html);
        $this->assertStringContainsString('/rfqs/62/messages/5001/files/0', $html);
        // Письмо запроса и есть «отправлено» — отдельного события нет.
        $this->assertStringNotContainsString('Запрос отправлен через QuoteParts', $html);
    }
}

/** Компонент-хозяин диалога для теста: снимок в памяти, ответ поставщику не показываем (нет пользователя). */
class RfqDialogHost extends Component
{
    use AnswersQuotePartsSuppliers;

    public function canAnswerQuoteParts(): bool
    {
        return false;
    }

    protected function quotePartsRfqForAnswer(int $id): QuotePartsRfq
    {
        throw new \LogicException('not used');
    }

    public function render()
    {
        // Как на страницах: диалог внутри общего корня компонента.
        return <<<'BLADE'
            <div>@include('livewire.quote-parts._rfq-dialog', $this->dialogData())</div>
            BLADE;
    }

    /** @return array<string, mixed> */
    public function dialogData(): array
    {
        $rfq = new QuotePartsRfq;
        $rfq->forceFill([
            'id' => 80, 'remote_id' => 62, 'tag' => '[QP-6ZHY80]', 'request_id' => null,
            'created_at' => Carbon::parse('2026-10-22 10:13'), 'synced_at' => Carbon::parse('2026-10-26 11:52'),
            'snapshot' => [
                'status' => 'open',
                'question_groups' => [
                    ['id' => 22, 'status' => 'open', 'title_ru' => 'Ошибка в базе данных', 'item_code' => 'M32178',
                        'questions' => [['supplier' => 'org:21', 'text' => 'Strange there is a mistake in your database', 'text_ru' => 'Странно, в вашей базе ошибка', 'at' => '2026-10-26T08:33:35+00:00']]],
                ],
                'suppliers' => [[
                    'external_id' => 'org:21', 'sent_at' => '2026-10-22T07:13:16+00:00', 'opened_at' => '2026-10-23T01:48:54+00:00',
                    'letters' => [['at' => '2026-10-22T07:13:16+00:00', 'to' => 'Steven <steven@oss-elevator-parts.com>', 'subject' => 'MyZip: request for quotation, 1 item [QP-6ZHY80]', 'text' => 'Hello, MyZip asks you to quote the items below.']],
                    'thread' => [
                        ['direction' => 'in', 'at' => '2026-10-23T01:51:45+00:00', 'who' => 'Steven (OSS Китай)', 'text' => 'Could you send photo for this?', 'files' => []],
                        ['direction' => 'out', 'at' => '2026-10-26T04:09:04+00:00', 'who' => 'dmitry.rumiantsev@myzip.ru', 'text' => 'Dear Steven, Photo in attach file.',
                            'files' => [['name' => 'photo.jpg', 'mime' => 'image/jpeg', 'message_id' => 5001, 'index' => 0]]],
                    ],
                    'questions' => [], 'lines' => [['code' => 'M32178', 'status' => 'question']],
                ]],
            ],
        ]);
        $rfq->setRelation('createdBy', null);

        return ['rfq' => $rfq, 'supplierNames' => ['org:21' => 'OSS Китай'], 'onlySupplier' => 'org:21', 'refreshAction' => 'refresh'];
    }
}
