<?php

namespace Tests\Unit\CalendarPoll;

use App\Mail\CalendarPollInviteMail;
use App\Models\CalendarPoll;
use App\Services\CalendarPoll\CalendarPollService;
use App\Services\CalendarPoll\VoterAccess;
use App\Services\CalendarPoll\VoterListParser;
use Tests\TestCase;

/** Опрос «Фото для календаря» (17.11.2026): баллы за места, список участников, ссылки, письмо. */
class CalendarPollTest extends TestCase
{
    public function test_points_by_place_and_tie_breaks(): void
    {
        // 6 мест: 1-е — 6 баллов, 6-е — 1. Фото 10: 1-е и 6-е = 7; фото 20: 2-е и 5-е = 7, тоже 2 голоса, но без первого места.
        $rows = CalendarPollService::score([10, 20, 30, 40], [10 => [1, 6], 20 => [2, 5], 30 => [3]], 6);

        $this->assertSame([10, 20, 30, 40], array_column($rows, 'id'));
        $this->assertSame([7, 7, 4, 0], array_column($rows, 'points'));
        $this->assertSame([1, 0, 0, 0], array_column($rows, 'firsts'));
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'place'));
    }

    public function test_more_votes_beat_equal_points_and_upload_order_breaks_full_ties(): void
    {
        // Фото 2: 6 баллов одним голосом (1-е); фото 1: 6 баллов тремя голосами (2+2+2 = 5-е,5-е,5-е) — больше голосов выше.
        $rows = CalendarPollService::score([1, 2, 3, 4], [1 => [5, 5, 5], 2 => [1], 3 => [4], 4 => [4]], 6);

        $this->assertSame([1, 2, 3, 4], array_column($rows, 'id'));
    }

    public function test_voter_list_accepts_excel_mail_and_plain_lines(): void
    {
        $r = (new VoterListParser())->parse("Иван Петров;\tIvan.Petrov@Example.ru\nАнна Смирнова <anna@example.ru>\nsergey.ivanov@example.ru\nбез адреса\nanna@example.ru");

        $this->assertSame([
            ['name' => 'Иван Петров', 'email' => 'ivan.petrov@example.ru'],
            ['name' => 'Анна Смирнова', 'email' => 'anna@example.ru'],
            ['name' => 'Sergey Ivanov', 'email' => 'sergey.ivanov@example.ru'],
        ], $r['valid']);
        $this->assertSame(['без адреса'], $r['invalid']);
        $this->assertSame(['anna@example.ru'], $r['duplicates']);
    }

    public function test_link_token_is_stored_hashed_and_email_masked(): void
    {
        $this->assertSame(hash('sha256', 'abc'), CalendarPollService::hash('abc'));
        $this->assertSame('i***@example.ru', VoterAccess::maskEmail('ivan.petrov@example.ru'));
    }

    public function test_poll_accepts_votes_only_while_open_and_before_deadline(): void
    {
        $poll = new CalendarPoll(['status' => CalendarPoll::STATUS_OPEN, 'closes_at' => now()->addDay()]);
        $this->assertTrue($poll->acceptsVotes());
        $poll->closes_at = now()->subMinute();
        $this->assertFalse($poll->acceptsVotes());
        $this->assertFalse((new CalendarPoll(['status' => CalendarPoll::STATUS_DRAFT]))->acceptsVotes());
    }

    public function test_invite_mail_carries_the_personal_link_and_the_rules(): void
    {
        $closes = \Illuminate\Support\Carbon::parse('2026-11-18 12:35', config('app.timezone'));
        $mail = new CalendarPollInviteMail('Иван Петров', 'Фото для календаря 2027', '', 'https://mzcorp.ru/calendar/'.str_repeat('a', 48), 6, $closes, 14, photos: 19, winners: 12);

        $this->assertSame('Фото для календаря 2027: выберите 6 лучших фото до 18.11', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('https://mzcorp.ru/calendar/'.str_repeat('a', 48), $html);
        $this->assertStringContainsString('Иван Петров, здравствуйте', $html);
        $this->assertStringContainsString('В опросе 19 снимков — в календарь попадут 12 фото', $html);
        $this->assertStringContainsString('Первое место приносит снимку 6 баллов', $html);
        $this->assertStringContainsString('Голосование открыто до 18.11.2026, 12:35.', $html);
        $this->assertStringContainsString('действует до конца голосования.', $html);
    }
}
