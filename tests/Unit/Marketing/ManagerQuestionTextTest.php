<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\MediaDataService;
use PHPUnit\Framework\TestCase;

/**
 * Вопросы менеджеров для серии советов: без подписи, только уточнения.
 */
class ManagerQuestionTextTest extends TestCase
{
    public function test_signature_is_cut_and_only_questions_pass(): void
    {
        $this->assertSame(
            'Добрый день, Крепеж на гайку или двусторонний скотч?',
            MediaDataService::questionText("Добрый день,\nКрепеж на гайку или двусторонний скотч?\n\nС уважением / With best regards,\nДмитрий Якубович"),
        );
        $this->assertSame(
            'Добрый день! цвет индикации? реальные фото кнопок со стрелками?',
            MediaDataService::questionText("Добрый день!\nцвет индикации?\nреальные фото кнопок со стрелками?\n-------------------------------------------\nС уважением"),
        );
        // Автоответ и письмо без вопроса — не уточнение.
        $this->assertNull(MediaDataService::questionText('Ваше письмо успешно получено и принято в работу.'));
        $this->assertNull(MediaDataService::questionText("КП во вложении.\nС уважением, Илья"));
    }
}
