<?php

namespace Tests\Unit\Services\Quotes;

use App\Enums\RequestStatus;
use App\Models\Request as RequestModel;
use App\Services\Quotes\OutboundQuoteRequestResolver;
use Tests\TestCase;

/**
 * Чей КП: заявки треда или заявки с его номером 1С.
 *
 * Кейс M-2026-20451 (шкив, 1С 371400): менеджер ответил в её тред КП 371525 — номер 1С
 * заявки M-2026-1492 (ролики) того же клиента. КП записался на шкив и сдвинул его в
 * «КП отправлено», строка КП прилипла к шкиву. Ещё два таких за историю (КП новых
 * заявок в старых закрытых тредах клиента) — переносим. Без номера 1С у заявки треда,
 * при чужом клиенте, закрытой или неоднозначной заявке — не переносим.
 *
 * БД не нужна — pick() работает на готовом списке кандидатов.
 */
class OutboundQuoteRequestResolverTest extends TestCase
{
    private function request(int $id, ?string $onec, ?int $org, string $email, RequestStatus $status = RequestStatus::InProgress): RequestModel
    {
        $r = new RequestModel;
        $r->id = $id;
        $r->onec_number = $onec;
        $r->organization_id = $org;
        $r->client_email = $email;
        $r->status = $status;

        return $r;
    }

    private function pick(RequestModel $thread, string $number, array $candidates): ?RequestModel
    {
        return (new OutboundQuoteRequestResolver)->pick($thread, $number, collect($candidates));
    }

    public function test_the_quote_goes_to_the_request_with_its_onec_number(): void
    {
        $sheave = $this->request(20473, '371400', 68, 'buyer@liftconnect.ru', RequestStatus::InProgress);
        $rollers = $this->request(1492, '371525', 68, 'other.buyer@liftconnect.ru', RequestStatus::AwaitingClientClarification);

        $this->assertSame(1492, $this->pick($sheave, '371525', [$rollers])?->id);
    }

    public function test_the_same_client_by_email_without_an_organization(): void
    {
        $thread = $this->request(1, '370434', null, 'Buyer@Client.ru', RequestStatus::ClosedLost);
        $owner = $this->request(2, '370755', null, 'buyer@client.ru', RequestStatus::Invoiced);

        $this->assertSame(2, $this->pick($thread, '370755', [$owner])?->id);
    }

    public function test_a_thread_without_its_own_number_keeps_the_quote(): void
    {
        // Старый КП, переотправленный в новой заявке клиента, — КП этой заявки.
        $thread = $this->request(1, null, 68, 'buyer@client.ru');
        $old = $this->request(2, '369905', 68, 'buyer@client.ru');

        $this->assertNull($this->pick($thread, '369905', [$old]));
    }

    public function test_the_threads_own_number_keeps_the_quote(): void
    {
        $thread = $this->request(1, ' 371400 ', 68, 'buyer@client.ru');
        $other = $this->request(2, '371400', 68, 'buyer@client.ru');

        $this->assertNull($this->pick($thread, '371400', [$other]));
    }

    public function test_another_client_keeps_the_quote(): void
    {
        // Номер чужого клиента — скорее опечатка в номере, чем КП не туда.
        $thread = $this->request(1, '371164', 10, 'a@one.ru');
        $stranger = $this->request(2, '371162', 11, 'b@two.ru');

        $this->assertNull($this->pick($thread, '371162', [$stranger]));
    }

    public function test_a_closed_request_does_not_take_the_quote(): void
    {
        $thread = $this->request(1, '371400', 68, 'buyer@client.ru');
        $closed = $this->request(2, '371525', 68, 'buyer@client.ru', RequestStatus::ClosedLost);

        $this->assertNull($this->pick($thread, '371525', [$closed]));
    }

    public function test_two_requests_with_the_number_is_ambiguous(): void
    {
        $thread = $this->request(1, '371400', 68, 'buyer@client.ru');

        $this->assertNull($this->pick($thread, '371525', [
            $this->request(2, '371525', 68, 'buyer@client.ru'),
            $this->request(3, '371525', 68, 'buyer@client.ru'),
        ]));
    }
}
