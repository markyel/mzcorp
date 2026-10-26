<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsThread;
use Tests\TestCase;

/**
 * Лента переписки с поставщиком — одна на все места (QuotePartsThread::of): письмо запроса, его ответы,
 * наши ответы и события по времени; «отправлено» — само письмо запроса, событием только без его копии.
 */
class QuotePartsThreadOfTest extends TestCase
{
    private static function supplier(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'org:21',
            'sent_at' => '2026-10-22T07:13:16+00:00',
            'opened_at' => '2026-10-23T01:48:54+00:00',
            'letters' => [['at' => '2026-10-22T07:13:16+00:00', 'to' => 'Steven <steven@oss.example>', 'subject' => 'MyZip: request for quotation [QP-6ZHY80]', 'text' => 'Hello']],
            'thread' => [
                ['direction' => 'in', 'at' => '2026-10-23T01:51:45+00:00', 'who' => 'Steven (OSS)', 'text' => 'Could you send photo?'],
                ['direction' => 'out', 'at' => '2026-10-26T04:09:04+00:00', 'who' => 'dmitry@myzip.ru', 'text' => 'Photo in attach file.'],
            ],
        ], $overrides);
    }

    public function test_letters_replies_and_events_go_in_time_order_without_a_duplicate_sent_event(): void
    {
        $thread = QuotePartsThread::of(self::supplier());

        $this->assertSame(['out', 'event', 'in', 'out'], array_column($thread, 'direction'));
        $this->assertSame('кому: Steven <steven@oss.example>', $thread[0]['who']);
        $this->assertSame('Поставщик открыл форму запроса', $thread[1]['text']);
        $this->assertNotContains('Запрос отправлен через QuoteParts', array_column($thread, 'text'));
    }

    public function test_without_a_copy_of_the_request_letter_the_sending_is_an_event(): void
    {
        $thread = QuotePartsThread::of(self::supplier(['letters' => [], 'thread' => [], 'opened_at' => null]));

        $this->assertSame([['direction' => 'event', 'at' => '2026-10-22T07:13:16+00:00', 'text' => 'Запрос отправлен через QuoteParts']], $thread);
    }

    public function test_reminders_are_one_event(): void
    {
        $thread = QuotePartsThread::of(self::supplier(['reminders_sent' => 2, 'last_reminder_at' => '2026-10-25T08:15:00+00:00']));

        $this->assertContains('QuoteParts отправил напоминание (всего 2) по позициям без ответа', array_column($thread, 'text'));
    }
}
