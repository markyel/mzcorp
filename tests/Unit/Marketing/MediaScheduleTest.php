<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaTopic;
use App\Services\Marketing\MediaAutopilotService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Расписание медиаплана: одна тема в день и срок после опоздания
 * (29.09.2026: в Telegram разом вышли три темы, а понедельничная тема,
 * вышедшая во вторник, уехала на 12.10 вместо 05.10).
 */
class MediaScheduleTest extends TestCase
{
    private function topic(int $id, string $title, int $weekday, int $cadence, string $due): MediaTopic
    {
        $t = new MediaTopic(['title' => $title, 'publish_weekday' => $weekday, 'cadence_days' => $cadence, 'next_due_on' => $due, 'is_active' => true]);
        $t->id = $id;

        return $t;
    }

    public function test_late_release_keeps_the_weekly_rhythm(): void
    {
        $monday = $this->topic(1, 'Новые позиции', 1, 7, '2026-09-28');

        // Вышла во вторник 29.09 — следующий понедельник 05.10, а не 12.10.
        $this->assertSame('2026-10-05', $monday->nextDueAfter(Carbon::parse('2026-09-29'), $monday->next_due_on)->toDateString());
        // Вышла в срок — ровно через неделю.
        $this->assertSame('2026-10-05', $monday->nextDueAfter(Carbon::parse('2026-09-28'), $monday->next_due_on)->toDateString());
        // Раз в две недели от 17.09, вышла 29.09 — ритм сохраняется: 01.10.
        $biweekly = $this->topic(4, 'Советы', 4, 14, '2026-09-17');
        $this->assertSame('2026-10-01', $biweekly->nextDueAfter(Carbon::parse('2026-09-29'), $biweekly->next_due_on)->toDateString());
    }

    public function test_a_second_channel_of_the_same_release_does_not_move_the_due_date_again(): void
    {
        $digest = $this->topic(6, 'Дайджест новостей отрасли', 5, 7, '2026-10-09');
        $friday = Carbon::parse('2026-10-09 09:15');

        // Первый канал выпуска (автопубликация, запланирован на срок) — сдвигает срок.
        $telegram = new \App\Models\MediaPublication(['planned_for' => '2026-10-09']);
        $this->assertTrue($digest->shouldAdvance($telegram, $friday));
        $digest->next_due_on = $digest->nextDueAfter($friday, $digest->next_due_on);
        $digest->advanced_on = $friday->copy();
        $this->assertSame('2026-10-16', $digest->next_due_on->toDateString());

        // ВКонтакте того же выпуска в 09:19, без даты плана — срок не трогает (было: 23.10).
        $vk = new \App\Models\MediaPublication(['planned_for' => null]);
        $this->assertFalse($digest->shouldAdvance($vk, Carbon::parse('2026-10-09 09:19')));

        // Следующий выпуск 16.10 — снова сдвигает.
        $this->assertTrue($digest->shouldAdvance(new \App\Models\MediaPublication(['planned_for' => '2026-10-16']), Carbon::parse('2026-10-16 09:15')));
    }

    public function test_own_weekday_topic_goes_first_then_the_most_overdue(): void
    {
        $tuesday = Carbon::parse('2026-09-29');
        $queue = MediaAutopilotService::queueFor(collect([
            $this->topic(4, 'Советы', 4, 14, '2026-09-24'),
            $this->topic(1, 'Новые позиции', 1, 7, '2026-09-28'),
            $this->topic(2, 'Снижение цен', 2, 7, '2026-09-29'),
        ]), $tuesday);

        $this->assertSame(['Снижение цен', 'Советы', 'Новые позиции'], $queue->pluck('title')->all());
    }
}
