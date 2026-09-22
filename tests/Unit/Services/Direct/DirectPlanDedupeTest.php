<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectAdPlanService as Plan;
use PHPUnit\Framework\TestCase;

/**
 * Одна фраза — одно объявление: по совпавшей фразе Директ показывает только
 * одно объявление рекламодателя (правила показа, п. 3.8), поэтому вторая
 * позиция с тем же кодом производителя показов не добавляет, а отнимает.
 */
class DirectPlanDedupeTest extends TestCase
{
    public function test_a_phrase_belongs_to_the_position_higher_in_the_queue(): void
    {
        $taken = [];

        $first = Plan::claimKeywords(['go50aex', 'go50aex купить'], $taken);
        $second = Plan::claimKeywords(['go50aex', 'go50aex купить', 'g050aex'], $taken);
        $third = Plan::claimKeywords(['go50aex'], $taken);

        $this->assertSame(['go50aex', 'go50aex купить'], $first, 'Первая позиция забирает свои коды');
        $this->assertSame(['g050aex'], $second, 'Второй остаётся только не занятое');
        $this->assertSame([], $third, 'Третьей рекламировать нечем — о ней предупредим замечанием');
    }

    public function test_the_same_article_written_two_ways_gives_two_phrases(): void
    {
        // Буква O и ноль в коде — разные фразы, и обе нужны: клиент набирает
        // и так, и так. Дедупликация не должна их склеивать.
        $this->assertNotSame(Plan::normalizeKeyword('GO50AEX'), Plan::normalizeKeyword('G050AEX'));
    }
}
