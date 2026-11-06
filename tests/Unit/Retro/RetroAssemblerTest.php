<?php

namespace Tests\Unit\Retro;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Services\Retro\RetroAssembler;
use App\Services\Retro\RetroIndex;
use PHPUnit\Framework\TestCase;

class RetroAssemblerTest extends TestCase
{
    private const NOW = 1793000000; // ноябрь 2026

    private const MANAGER = 'manager@myzip.ru';

    private int $uid = 0;

    private function assembler(array $override = []): RetroAssembler
    {
        return new RetroAssembler($override + [
            'internal_domains' => ['myzip.ru'],
            'free_mail_domains' => ['gmail.com'],
            'service_sender_pattern' => '/^(mailer-daemon|no-?reply)[^@]*@/i',
            'supplier_min_requests' => 3,
            'max_legacy_number' => 25000,
            'year' => 2026,
            'idle_close_days' => 0,
            'mz_folder_owners' => ['MZ|Other' => 'other@myzip.ru'],
        ], [self::MANAGER, 'other@myzip.ru'], self::NOW);
    }

    private function sent(string $mid, int $day, array $extra = []): array
    {
        return RetroIndex::normalize($extra + [
            'uid' => ++$this->uid, 'folder' => 'Sent', 'date' => date('r', self::NOW - 86400 * (100 - $day)),
            'from' => self::MANAGER, 'to' => 'ivan@client.ru', 'subject' => 'Re: запрос', 'mid' => "<$mid>",
        ], self::MANAGER, 2026, 25000);
    }

    private function inbound(string $mid, int $day, array $extra = []): array
    {
        return RetroIndex::normalize($extra + [
            'uid' => ++$this->uid, 'folder' => 'INBOX', 'date' => date('r', self::NOW - 86400 * (100 - $day)),
            'from' => 'Иван <ivan@client.ru>', 'to' => self::MANAGER, 'subject' => 'Запрос', 'mid' => "<$mid>",
        ], self::MANAGER, 2026, 25000);
    }

    public function test_footer_gives_the_number_and_the_chain_inherits_it_both_ways(): void
    {
        $result = $this->assembler()->assemble([
            $this->inbound('a@x', 1),
            $this->sent('b@x', 2, ['irt' => '<a@x>', 'refs' => '<a@x>', 'foot' => ['M-2026-1234']]),
            $this->inbound('c@x', 3, ['irt' => '<b@x>', 'refs' => '<a@x> <b@x>']),
            $this->inbound('stranger@x', 3, ['from' => 'other@other.ru']),
        ]);

        $this->assertSame([1234], array_keys($result['requests']));
        $request = $result['requests'][1234];
        $this->assertCount(3, $request['messages']);
        $this->assertSame('M-2026-1234', $request['code']);
        $this->assertSame('ivan@client.ru', $request['client_email']);
        $this->assertSame('Иван', $request['client_name']);
        $this->assertSame('Запрос', $request['subject']);
        $this->assertSame(1, $result['stats']['unassigned_rows']);
    }

    public function test_sibling_reply_gets_the_number_through_the_common_parent(): void
    {
        $result = $this->assembler()->assemble([
            $this->inbound('origin@x', 1),
            // КП ушёл ответом из Яндекса, без подвала.
            $this->sent('quote@x', 2, ['irt' => '<origin@x>', 'refs' => '<origin@x>', 'names' => ['Предложение МЗ-111 от 2026-06-01_10-00-00.pdf']]),
            // Другой ответ на то же письмо ушёл из CRM, с подвалом.
            $this->sent('crm@x', 3, ['irt' => '<origin@x>', 'refs' => '<origin@x>', 'foot' => ['M-2026-1000']]),
        ]);

        $this->assertCount(3, $result['requests'][1000]['messages']);
        $this->assertCount(1, $result['requests'][1000]['quotes']);
    }

    public function test_numbers_above_the_legacy_limit_are_noise(): void
    {
        $result = $this->assembler()->assemble([$this->sent('a@x', 1, ['foot' => ['M-2026-230628']])]);

        $this->assertSame([], $result['requests']);
    }

    public function test_short_number_gets_leading_zeros_like_the_live_generator(): void
    {
        $result = $this->assembler()->assemble([$this->sent('a@x', 1, ['foot' => ['M-2026-987']])]);

        $this->assertSame('M-2026-0987', $result['requests'][987]['code']);
    }

    public function test_status_follows_documents_and_last_quote_number_goes_to_onec(): void
    {
        $result = $this->assembler()->assemble([
            $this->sent('a@x', 1, ['foot' => ['M-2026-1000'], 'names' => ['Предложение МЗ-111 от 2026-06-01_10-00-00.pdf']]),
            $this->sent('b@x', 2, ['foot' => ['M-2026-1000'], 'names' => ['Предложение МЗ-222 от 2026-06-02_10-00-00.pdf']]),
            $this->sent('c@x', 1, ['foot' => ['M-2026-2000'], 'names' => ['Предложение МЗ-333 от 2026-06-01_10-00-00.pdf']]),
            $this->sent('d@x', 3, ['foot' => ['M-2026-2000'], 'names' => ['Счет МЗ-44 от 2026-06-03_10-00-00.pdf', 'Счет МЗ-7143 от 2026-06-03_10-00-00.pdf']]),
            $this->sent('e@x', 1, ['foot' => ['M-2026-3000']]),
        ]);

        $this->assertSame(RequestStatus::Quoted, $result['requests'][1000]['status']);
        $this->assertSame('222', $result['requests'][1000]['onec_number']);
        $this->assertSame(RequestStatus::Invoiced, $result['requests'][2000]['status']);
        $this->assertSame('333', $result['requests'][2000]['onec_number'], 'Номер счёта не должен попасть в номер 1С');
        $this->assertCount(1, $result['requests'][2000]['invoices'], 'Счет МЗ-44 — номер короче трёх цифр, не документ');
        $this->assertSame(RequestStatus::InProgress, $result['requests'][3000]['status']);
    }

    public function test_same_document_sent_twice_counts_once(): void
    {
        $name = 'Счет МЗ-7143 от 2026-06-03_10-00-00.pdf';
        $result = $this->assembler()->assemble([
            $this->sent('a@x', 1, ['foot' => ['M-2026-1000'], 'names' => [$name]]),
            $this->sent('b@x', 2, ['foot' => ['M-2026-1000'], 'names' => [$name]]),
        ]);

        $this->assertCount(1, $result['requests'][1000]['invoices']);
    }

    public function test_supplier_chain_is_marked_and_does_not_become_the_client(): void
    {
        $result = $this->assembler()->assemble([
            $this->inbound('client@x', 1, ['subject' => 'Нужны кнопки']),
            $this->sent('reply@x', 2, ['irt' => '<client@x>', 'refs' => '<client@x>', 'foot' => ['M-2026-1000']]),
            // Запрос поставщику с тем же номером, отдельная цепочка.
            $this->sent('rfq@x', 3, ['to' => 'sales@supplier.ru', 'foot' => ['M-2026-1000']]),
            $this->inbound('offer@x', 4, ['from' => 'sales@supplier.ru', 'irt' => '<rfq@x>', 'refs' => '<rfq@x>']),
        ]);

        $request = $result['requests'][1000];
        $this->assertSame('ivan@client.ru', $request['client_email']);
        $this->assertSame([false, false, true, true], array_column($request['messages'], 'supplier'));
    }

    public function test_recipient_of_a_document_is_the_client_even_if_a_supplier_wrote_first(): void
    {
        $result = $this->assembler()->assemble([
            $this->inbound('offer@x', 1, ['from' => 'sales@supplier.ru', 'codes' => ['M-2026-1000']]),
            $this->sent('quote@x', 2, ['foot' => ['M-2026-1000'], 'names' => ['Предложение МЗ-111 от 2026-06-01_10-00-00.pdf']]),
        ]);

        $this->assertSame('ivan@client.ru', $result['requests'][1000]['client_email']);
    }

    public function test_frequent_recipient_without_documents_is_treated_as_supplier(): void
    {
        $rows = [];
        foreach ([1001, 1002, 1003] as $n) {
            $rows[] = $this->sent("rfq$n@x", 1, ['to' => 'sales@supplier.ru', 'foot' => ["M-2026-$n"]]);
        }
        $rows[] = $this->inbound('client@x', 2, ['codes' => ['M-2026-1001']]);

        $result = $this->assembler()->assemble($rows);

        $this->assertSame('ivan@client.ru', $result['requests'][1001]['client_email']);
    }

    public function test_free_mail_domain_does_not_join_different_clients(): void
    {
        $result = $this->assembler()->assemble([
            $this->inbound('a@x', 1, ['from' => 'one@gmail.com', 'codes' => ['M-2026-1000']]),
            $this->sent('b@x', 2, ['to' => 'two@gmail.com', 'foot' => ['M-2026-1000']]),
        ]);

        $this->assertSame([false, true], array_column($result['requests'][1000]['messages'], 'supplier'));
    }

    public function test_same_message_in_two_mailboxes_keeps_one_primary_row(): void
    {
        $copy = RetroIndex::normalize([
            'uid' => 1, 'folder' => 'MZ|Other', 'date' => date('r', self::NOW - 86400), 'from' => self::MANAGER,
            'to' => 'ivan@client.ru', 'subject' => 'Re', 'mid' => '<a@x>',
        ], 'info@myzip.ru', 2026, 25000);
        $result = $this->assembler()->assemble([$copy, $this->sent('a@x', 99, ['foot' => ['M-2026-1000']])]);

        $messages = $result['requests'][1000]['messages'];
        $this->assertCount(2, $messages);
        $this->assertCount(1, array_filter($messages, fn ($m) => $m['copy_of'] === null));
        $primary = array_values(array_filter($messages, fn ($m) => $m['copy_of'] === null))[0];
        $this->assertSame('Sent', $result['rows'][$primary['i']]['folder']);
    }

    public function test_responsible_is_last_replying_manager_and_mz_folder_is_the_fallback(): void
    {
        $mz = RetroIndex::normalize([
            'uid' => 1, 'folder' => 'MZ|Other', 'date' => date('r', self::NOW - 86400 * 90), 'from' => 'ivan@client.ru',
            'to' => 'info@myzip.ru', 'subject' => 'Запрос', 'mid' => '<a@x>', 'codes' => ['M-2026-1000'],
        ], 'info@myzip.ru', 2026, 25000);
        $withReply = $this->assembler()->assemble([$mz, $this->sent('b@x', 50, ['irt' => '<a@x>', 'refs' => '<a@x>'])]);
        $this->assertSame(self::MANAGER, $withReply['requests'][1000]['assigned']);
        $this->assertSame('other@myzip.ru', $withReply['requests'][1000]['first_assigned']);

        $alone = $this->assembler()->assemble([$mz]);
        $this->assertSame('other@myzip.ru', $alone['requests'][1000]['assigned']);
        $this->assertSame(RequestStatus::Assigned, $alone['requests'][1000]['status']);
    }

    public function test_reply_from_shared_mailbox_moves_status_but_not_responsibility(): void
    {
        $shared = RetroIndex::normalize([
            'uid' => 1, 'folder' => 'Sent', 'date' => date('r', self::NOW - 86400), 'from' => 'info@myzip.ru',
            'to' => 'ivan@client.ru', 'subject' => 'Re', 'mid' => '<a@x>', 'codes' => ['M-2026-1000'],
        ], 'info@myzip.ru', 2026, 25000);
        $request = $this->assembler()->assemble([$shared])['requests'][1000];

        $this->assertNull($request['assigned']);
        $this->assertSame(RequestStatus::InProgress, $request['status']);
    }

    public function test_marked_price_request_is_supplier_correspondence_and_never_the_client(): void
    {
        $result = $this->assembler()->assemble([
            $this->sent('rfq@x', 1, ['to' => 'sales@supplier.ru', 'codes' => ['M-2026-1000'],
                'subject' => 'Запрос расценки — [M-2026-1000] / [360001] [RFQ-ABCD12]']),
            $this->inbound('offer@x', 2, ['from' => 'sales@supplier.ru', 'irt' => '<rfq@x>', 'refs' => '<rfq@x>',
                'subject' => 'RE: Запрос расценки — [M-2026-1000] / [360001] [RFQ-ABCD12]']),
        ]);

        $request = $result['requests'][1000];
        $this->assertNull($request['client_email'], 'Письма клиента в почте нет — клиента нет');
        $this->assertSame([true, true], array_column($request['messages'], 'supplier'));
        $this->assertSame('no_client', $request['review']['reason']);
        $this->assertSame('sales@supplier.ru', $request['review']['previous']);
        $this->assertSame(self::MANAGER, $request['assigned'], 'Без писем клиенту ответственный — автор запроса поставщику');
        $this->assertSame(self::MANAGER, $request['first_assigned']);
    }

    public function test_dealer_is_the_client_of_its_own_request_and_a_supplier_in_ours(): void
    {
        $result = $this->assembler()->assemble([
            // Дилер — клиент своей заявки: получил наш КП.
            $this->inbound('d1@x', 1, ['from' => 'buyer@dealer.ru', 'codes' => ['M-2026-2000']]),
            $this->sent('d2@x', 2, ['to' => 'buyer@dealer.ru', 'irt' => '<d1@x>', 'refs' => '<d1@x>', 'foot' => ['M-2026-2000'],
                'names' => ['Предложение МЗ-360002 от 2026-06-02_10-00-00.pdf']]),
            // Он же отвечает на наш запрос расценки по чужой заявке.
            $this->inbound('c1@x', 3, ['codes' => ['M-2026-1000']]),
            $this->sent('rfq@x', 4, ['to' => 'buyer@dealer.ru', 'codes' => ['M-2026-1000'], 'subject' => 'Запрос расценки — [M-2026-1000]']),
        ]);

        $this->assertSame('buyer@dealer.ru', $result['requests'][2000]['client_email']);
        $this->assertNull($result['requests'][2000]['review']);
        $this->assertSame('ivan@client.ru', $result['requests'][1000]['client_email']);
        $this->assertSame([false, true], array_column($result['requests'][1000]['messages'], 'supplier'));
    }

    public function test_client_that_would_change_is_kept_and_sent_to_review(): void
    {
        $result = $this->assembler()->assemble([
            $this->inbound('offer@x', 1, ['from' => 'sales@supplier.ru', 'codes' => ['M-2026-1000'],
                'subject' => 'RE: Price request — [M-2026-1000]']),
            $this->inbound('client@x', 2, ['codes' => ['M-2026-1000']]),
        ]);

        $request = $result['requests'][1000];
        $this->assertSame('client_changed', $request['review']['reason']);
        $this->assertSame('ivan@client.ru', $request['review']['found']);
        $this->assertSame('sales@supplier.ru', $request['client_email'], 'Клиента сами не меняем, только на проверку');
    }

    public function test_chain_without_number_joins_the_request_by_the_onec_number_of_our_quote(): void
    {
        $quote = 'Предложение МЗ-360001 от 2026-06-01_10-00-00.pdf';
        $result = $this->assembler(['crash_date' => date('Y-m-d', self::NOW)])->assemble([
            $this->sent('a@x', 1, ['foot' => ['M-2026-1000'], 'names' => [$quote]]),
            // Тот же КП позже ушёл ответом из Яндекса, без подвала, в отдельной цепочке.
            $this->inbound('b@x', 50),
            $this->sent('c@x', 51, ['irt' => '<b@x>', 'refs' => '<b@x>', 'names' => [$quote]]),
        ]);

        $this->assertSame([1000], array_keys($result['requests']));
        $this->assertCount(3, $result['requests'][1000]['messages']);
        $this->assertSame(1, $result['stats']['attached_by_quote_file']);
        $this->assertSame('quote_file', $result['rows'][1]['attached']);
    }

    public function test_onec_number_in_the_subject_joins_only_the_same_client(): void
    {
        $rows = [
            $this->inbound('a@x', 1, ['codes' => ['M-2026-1000']]),
            $this->sent('rfq@x', 2, ['to' => 'sales@supplier.ru', 'codes' => ['M-2026-1000'], 'subject' => 'Запрос расценки — [M-2026-1000] / [360002]']),
            $this->inbound('b@x', 30, ['subject' => 'Уточнение по запросу (360002)']),
            $this->inbound('c@x', 31, ['from' => 'petr@other.ru', 'subject' => 'Счёт 360002']),
        ];
        $result = $this->assembler(['crash_date' => date('Y-m-d', self::NOW)])->assemble($rows);

        $this->assertSame(1, $result['stats']['attached_by_subject']);
        $this->assertCount(3, $result['requests'][1000]['messages'], 'Письмо чужого клиента с тем же числом не присоединяется');
    }

    public function test_chains_from_the_crash_day_on_and_ambiguous_ones_are_not_joined(): void
    {
        $quote = 'Предложение МЗ-360001 от 2026-06-01_10-00-00.pdf';
        $result = $this->assembler(['crash_date' => date('Y-m-d', self::NOW - 86400 * 10)])->assemble([
            $this->sent('a@x', 1, ['foot' => ['M-2026-1000'], 'names' => [$quote]]),
            $this->sent('b@x', 2, ['foot' => ['M-2026-2000'], 'names' => ['Предложение МЗ-360003 от 2026-06-01_10-00-00.pdf']]),
            // После аварии — не трогаем.
            $this->sent('late@x', 95, ['names' => [$quote]]),
            // КП из двух разных заявок — номер неоднозначен.
            $this->sent('two@x', 50, ['names' => [$quote, 'Предложение МЗ-360003 от 2026-06-01_10-00-00.pdf']]),
        ]);

        $this->assertCount(1, $result['requests'][1000]['messages']);
        $this->assertCount(1, $result['requests'][2000]['messages']);
        $this->assertSame(0, $result['stats']['attached_by_quote_file']);
        $this->assertSame(1, $result['stats']['attach_ambiguous']);
        $this->assertSame(2, $result['stats']['unassigned_rows']);
    }

    public function test_chains_without_a_number_become_r_requests_only_after_launch_with_our_document(): void
    {
        $quote = fn (int $n) => ['names' => ["Предложение МЗ-36000$n от 2026-06-01_10-00-00.pdf"]];
        $assembler = $this->assembler([
            'launch_date' => date('Y-m-d', self::NOW - 86400 * 90),
            'crash_date' => date('Y-m-d', self::NOW - 86400 * 10),
        ]);
        $result = $assembler->assemble([
            $this->sent('numbered@x', 30, ['foot' => ['M-2026-1000']]),
            // До запуска — не заявка.
            $this->inbound('early@x', 5, ['from' => 'early@client.ru']),
            $this->sent('early-q@x', 6, ['to' => 'early@client.ru', 'irt' => '<early@x>', 'refs' => '<early@x>'] + $quote(1)),
            // После запуска, клиент писал, менеджер ответил КП из почты, без номера.
            $this->inbound('b@x', 40, ['from' => 'Пётр <petr@b-client.ru>', 'subject' => 'Нужен ролик']),
            $this->sent('b-q@x', 41, ['to' => 'petr@b-client.ru', 'irt' => '<b@x>', 'refs' => '<b@x>'] + $quote(2)),
            $this->inbound('a@x', 35, ['from' => 'anna@a-client.ru']),
            $this->sent('a-q@x', 36, ['to' => 'anna@a-client.ru', 'irt' => '<a@x>', 'refs' => '<a@x>'] + $quote(3)),
            // Без нашего документа — не заявка.
            $this->inbound('chat@x', 50, ['from' => 'olga@c-client.ru']),
            // КП переслан только коллеге — не сделка.
            $this->sent('inner@x', 52, ['to' => 'other@myzip.ru'] + $quote(6)),
            // Запрос поставщику — не заявка.
            $this->sent('rfq@x', 50, ['to' => 'sales@supplier.ru', 'subject' => 'Запрос расценки — [360009]'] + $quote(4)),
            // После аварии — не трогаем.
            $this->sent('late@x', 95, ['to' => 'late@client.ru'] + $quote(5)),
        ]);
        $rows = $result['rows'];
        $requests = $assembler->assembleUnnumbered($rows, 7);

        $this->assertSame(['R-2026-0007', 'R-2026-0008'], array_column($requests, 'code'));
        $this->assertSame(['anna@a-client.ru', 'petr@b-client.ru'], array_column($requests, 'client_email'));
        $this->assertSame(self::MANAGER, array_values($requests)[1]['assigned']);
        $this->assertSame(RequestStatus::Quoted, array_values($requests)[1]['status']);
        $this->assertSame('Нужен ролик', array_values($requests)[1]['subject']);
        $this->assertSame(RetroAssembler::UNNUMBERED_KEY + 7, array_key_first($requests));
        $numbered = array_values(array_filter($rows, fn ($r) => $r['mid'] === 'numbered@x'))[0];
        $this->assertSame(1000, $numbered['number'], 'Письма заявок с номером не трогаются');
    }

    public function test_crash_days_requests_follow_the_forward_from_the_shared_box_and_direct_letters(): void
    {
        $info = 'info@myzip.ru';
        $at = fn (string $box, string $folder, string $mid, int $day, array $extra) => RetroIndex::normalize($extra + [
            'uid' => ++$this->uid, 'folder' => $folder, 'date' => date('r', self::NOW - 86400 * (100 - $day)), 'mid' => "<$mid>",
        ], $box, 2026, 25000);
        $assembler = $this->assembler(['crash_date' => date('Y-m-d', self::NOW - 86400 * 10)]);
        $result = $assembler->assemble([
            $this->sent('numbered@x', 30, ['foot' => ['M-2026-1000']]),
            // Продолжение существующей заявки после аварии — остаётся в ней.
            $this->inbound('numbered-re@x', 92, ['irt' => '<numbered@x>', 'refs' => '<numbered@x>']),
            // Письмо на info@, пересылка менеджеру, менеджер пишет клиенту с КП новой цепочкой.
            $at($info, 'INBOX', 'f1@x', 91, ['from' => 'Олег <oleg@f-client.ru>', 'to' => $info, 'subject' => 'Нужен датчик']),
            $at($info, 'Sent', 'f1-fw@x', 91, ['from' => $info, 'to' => self::MANAGER, 'subject' => 'Fwd: Нужен датчик']),
            $this->inbound('f1-fw@x', 91, ['from' => $info, 'subject' => 'Fwd: Нужен датчик']),
            $this->sent('f1-q@x', 92, ['to' => 'oleg@f-client.ru', 'subject' => '372001', 'names' => ['Предложение МЗ-372001 от 2026-11-03_10-00-00.pdf']]),
            // Переслано другому менеджеру, он ещё не ответил.
            $at($info, 'INBOX', 'f2@x', 93, ['from' => 'nina@g-client.ru', 'to' => $info, 'subject' => 'Кнопка вызова']),
            $at($info, 'Sent', 'f2-fw@x', 93, ['from' => $info, 'to' => 'other@myzip.ru', 'subject' => 'FW: Кнопка вызова']),
            // Клиент написал менеджеру лично.
            $this->inbound('d1@x', 94, ['from' => 'pavel@h-client.ru', 'subject' => 'Цена на ролик']),
            // Ответ на старую переписку без номера — не новое обращение.
            $this->inbound('old@x', 50, ['from' => 'olga@c-client.ru']),
            $this->inbound('old-re@x', 93, ['from' => 'olga@c-client.ru', 'irt' => '<old@x>', 'refs' => '<old@x>']),
            // Письмо на info@ без пересылки и ответа — менеджера нет.
            $at($info, 'INBOX', 'lost@x', 95, ['from' => 'spam@ads.ru', 'to' => $info, 'subject' => 'Реклама']),
        ]);
        $rows = $result['rows'];
        $crash = $assembler->assembleCrash($rows, [$info], 3);
        $requests = array_values($crash['requests']);

        $this->assertSame(['MZ2026-00003', 'MZ2026-00004', 'MZ2026-00005'], array_column($requests, 'code'));
        $this->assertSame(['oleg@f-client.ru', 'nina@g-client.ru', 'pavel@h-client.ru'], array_column($requests, 'client_email'));
        $this->assertSame([self::MANAGER, 'other@myzip.ru', self::MANAGER], array_column($requests, 'assigned'));
        $this->assertSame(['forwarded', 'forwarded', 'direct'], array_column($requests, 'crash_source'));
        $this->assertSame(RequestStatus::Quoted, $requests[0]['status']);
        $this->assertSame('372001', $requests[0]['onec_number']);
        $this->assertCount(4, $requests[0]['messages'], 'Письмо клиента, пересылка (две копии) и КП менеджера');
        $this->assertSame(RequestStatus::Assigned, $requests[1]['status']);
        $this->assertSame(RetroAssembler::CRASH_KEY + 3, array_key_first($crash['requests']));
        $this->assertSame(['нет менеджера'], array_column($crash['skipped'], 'reason'));
        $numbered = array_values(array_filter($rows, fn ($r) => $r['mid'] === 'numbered-re@x'))[0];
        $this->assertSame(1000, $numbered['number'], 'Продолжение заявки с номером остаётся в ней');

        // Выбранное вручную письмо без менеджера — заявка без ответственного.
        $rows = $result['rows'];
        $chosen = $assembler->assembleCrash($rows, [$info], 1, null, fn (array $chain) => in_array('lost@x', array_column($chain, 'mid'), true));
        $last = array_values($chosen['requests'])[3];
        $this->assertSame(['chosen', null, 'spam@ads.ru'], [$last['crash_source'], $last['assigned'], $last['client_email']]);
        $this->assertSame(RequestStatus::New, $last['status']);
        $this->assertSame([], $chosen['skipped']);
    }

    public function test_crash_days_keep_separate_requests_of_one_client_and_drop_mailings_and_supplier_requests(): void
    {
        $other = 'other@myzip.ru';
        $at = fn (string $box, string $folder, string $mid, int $day, array $extra) => RetroIndex::normalize($extra + [
            'uid' => ++$this->uid, 'folder' => $folder, 'date' => date('r', self::NOW - 86400 * (100 - $day) + $this->uid * 60), 'mid' => "<$mid>",
        ], $box, 2026, 25000);
        $assembler = $this->assembler(['crash_date' => date('Y-m-d', self::NOW - 86400 * 10)]);
        $result = $assembler->assemble([
            // Две разные заявки крупного клиента одному менеджеру в один день.
            $this->inbound('m1@x', 92, ['from' => 'a@meteor.ru', 'subject' => 'Вкладыш башмака']),
            $this->inbound('m2@x', 92, ['from' => 'b@meteor.ru', 'subject' => 'Доводчик двери']),
            // КП новым письмом после второго обращения — к нему.
            $this->sent('m2-q@x', 93, ['to' => 'b@meteor.ru', 'subject' => '372555', 'names' => ['Предложение МЗ-372555 от 2026-11-03_10-00-00.pdf']]),
            // Рассылка в два ящика.
            $this->inbound('ad1@x', 92, ['from' => '333@teko-com.ru', 'subject' => 'Вам в подарок 10%']),
            $at($other, 'INBOX', 'ad1@x-2', 92, ['from' => '333@teko-com.ru', 'to' => $other, 'subject' => 'Вам в подарок 10%']),
            // Запрос поставщику без маркировки CRM.
            $this->sent('rfq@x', 93, ['to' => 'info@snab-lift.ru', 'subject' => 'Запрос 000372683']),
            $this->inbound('rfq-re@x', 94, ['from' => 'info@snab-lift.ru', 'subject' => 'Re: Запрос 000372683', 'irt' => '<rfq@x>', 'refs' => '<rfq@x>']),
        ]);
        $rows = $result['rows'];
        $crash = $assembler->assembleCrash($rows, ['info@myzip.ru']);
        $requests = array_values($crash['requests']);

        $this->assertSame(['Вкладыш башмака', 'Доводчик двери'], array_column($requests, 'subject'));
        $this->assertCount(1, $requests[0]['messages']);
        $this->assertCount(2, $requests[1]['messages'], 'КП новым письмом — ко второму обращению');
        $this->assertSame(RequestStatus::Quoted, $requests[1]['status']);
        $reasons = array_column($crash['skipped'], 'reason');
        sort($reasons);
        $this->assertSame(['запрос поставщику', 'рассылка', 'рассылка'], $reasons);
    }

    public function test_crash_days_letters_without_subject_from_two_clients_stay_two_requests(): void
    {
        $info = 'info@myzip.ru';
        $other = 'other@myzip.ru';
        $at = fn (string $box, string $folder, string $mid, int $day, array $extra) => RetroIndex::normalize($extra + [
            'uid' => ++$this->uid, 'folder' => $folder, 'date' => date('r', self::NOW - 86400 * (100 - $day) + $this->uid * 600), 'mid' => "<$mid>",
        ], $box, 2026, 25000);
        $assembler = $this->assembler(['crash_date' => date('Y-m-d', self::NOW - 86400 * 10)]);
        $result = $assembler->assemble([
            // Два клиента без темы; пересылки Яндекса ссылаются на исходное письмо.
            $at($info, 'INBOX', 'k@x', 92, ['from' => 'kirill@svt.ru', 'to' => $info, 'subject' => '']),
            $at($info, 'Sent', 'k-fw@x', 92, ['from' => $info, 'to' => self::MANAGER, 'subject' => 'Fwd:', 'irt' => '<k@x>', 'refs' => '<k@x>']),
            $at($info, 'INBOX', 'r@x', 92, ['from' => 'reshto@sibocom.com', 'to' => $info, 'subject' => '']),
            $at($info, 'Sent', 'r-fw@x', 92, ['from' => $info, 'to' => $other, 'subject' => 'Fwd:', 'irt' => '<r@x>', 'refs' => '<r@x>']),
            // Пересылка без ссылки с общей темой — по теме не связываем.
            $at($info, 'INBOX', 'z1@x', 93, ['from' => 'a@one.ru', 'to' => $info, 'subject' => 'Запрос КП']),
            $at($info, 'INBOX', 'z2@x', 93, ['from' => 'b@two.ru', 'to' => $info, 'subject' => 'Запрос КП']),
            $at($info, 'Sent', 'z-fw@x', 93, ['from' => $info, 'to' => self::MANAGER, 'subject' => 'Fwd: Запрос КП']),
        ]);
        $rows = $result['rows'];
        $crash = $assembler->assembleCrash($rows, [$info]);
        $byClient = [];
        foreach ($crash['requests'] as $r) {
            $byClient[$r['client_email']] = $r['assigned'];
        }

        $this->assertSame(self::MANAGER, $byClient['kirill@svt.ru']);
        $this->assertSame($other, $byClient['reshto@sibocom.com']);
        $this->assertArrayNotHasKey('a@one.ru', $byClient, 'Общая тема без ссылки — пересылка ни к кому не привязана');
        $this->assertArrayNotHasKey('b@two.ru', $byClient);
        $this->assertTrue(RetroAssembler::isInformativeSubject('Нужен датчик'));
        $this->assertFalse(RetroAssembler::isInformativeSubject('Запрос КП'));
        $this->assertFalse(RetroAssembler::isInformativeSubject(''));
        $this->assertFalse(RetroAssembler::isInformativeSubject('Date: Mon, 02 Nov 2026 09:12'));
    }

    public function test_idle_requests_close_with_a_reason_for_their_stage_and_keep_the_peak(): void
    {
        $result = $this->assembler(['idle_close_days' => 30])->assemble([
            $this->sent('a@x', 1, ['foot' => ['M-2026-1000'], 'names' => ['Счет МЗ-7143 от 2026-06-03_10-00-00.pdf']]),
            $this->sent('b@x', 1, ['foot' => ['M-2026-2000'], 'names' => ['Предложение МЗ-111 от 2026-06-01_10-00-00.pdf']]),
            $this->sent('c@x', 95, ['foot' => ['M-2026-3000'], 'names' => ['Предложение МЗ-222 от 2026-06-01_10-00-00.pdf']]),
        ]);

        $this->assertSame(RequestStatus::ClosedLost, $result['requests'][1000]['status']);
        $this->assertSame(ClosedLostReason::InvoiceUnpaid, $result['requests'][1000]['closed']['reason']);
        $this->assertSame(RequestStatus::Invoiced, $result['requests'][1000]['peak']);
        $this->assertSame(ClosedLostReason::NoClientResponseToQuote, $result['requests'][2000]['closed']['reason']);
        $this->assertSame(RequestStatus::Quoted, $result['requests'][3000]['status'], 'Свежая заявка не закрывается');
        $this->assertNull($result['requests'][3000]['closed']);
    }
}
