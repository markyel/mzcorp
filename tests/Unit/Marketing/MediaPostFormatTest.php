<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaTopic;
use App\Services\Marketing\MediaPublisherService;
use Tests\TestCase;

/**
 * Оформление постов медиаплана: заголовки выделены, хэштеги в конце (16.10.2026, дайджест
 * отрасли в Telegram вышел сплошным текстом).
 */
class MediaPostFormatTest extends TestCase
{
    private const DIGEST = "Лифтовая отрасль за неделю: 9–15 октября 2026\n\n"
        ."На прошедшей неделе в лифтовой отрасли произошло множество событий.\n\n"
        ."Программы замены и модернизации:\n"
        ."Москва активно заменяет лифты <КМЗ> на отечественные модели.\n\n"
        ."Производство и поставки:\n"
        ."• Карачаровский завод увеличил выпуск: на 30%.\n\n"
        ."#лифты #MyZip";

    public function test_telegram_and_max_get_bold_title_and_sections(): void
    {
        ['text' => $text, 'html' => $html] = MediaPublisherService::formatHeadings(self::DIGEST, 'telegram', false);

        $this->assertTrue($html);
        $lines = explode("\n", $text);
        $this->assertSame('<b>Лифтовая отрасль за неделю: 9–15 октября 2026</b>', $lines[0]);
        $this->assertContains('<b>Программы замены и модернизации:</b>', $lines);
        $this->assertContains('<b>Производство и поставки:</b>', $lines);
        // Обычный текст не трогаем, «<» экранирован, пункт списка — не заголовок.
        $this->assertContains('Москва активно заменяет лифты &lt;КМЗ&gt; на отечественные модели.', $lines);
        $this->assertContains('• Карачаровский завод увеличил выпуск: на 30%.', $lines);
        $this->assertSame('#лифты #MyZip', end($lines));
    }

    public function test_already_html_text_keeps_its_links(): void
    {
        $in = "Новые позиции:\nКнопка <a href=\"https://x.ru/M1\">M00001</a> в наличии.";
        ['text' => $text] = MediaPublisherService::formatHeadings($in, 'max', true);

        $this->assertSame("<b>Новые позиции:</b>\nКнопка <a href=\"https://x.ru/M1\">M00001</a> в наличии.", $text);
    }

    public function test_vk_gets_a_mark_before_sections_and_no_markup(): void
    {
        ['text' => $text, 'html' => $html] = MediaPublisherService::formatHeadings(self::DIGEST, 'vk', false);

        $this->assertFalse($html);
        $this->assertStringContainsString("\n".MediaPublisherService::VK_HEADING_MARK."Программы замены и модернизации:\n", $text);
        $this->assertStringStartsWith('Лифтовая отрасль за неделю', $text);
        $this->assertStringNotContainsString('<b>', $text);
    }

    public function test_hashtags_by_topic_type_or_own_plus_common(): void
    {
        $digest = new MediaTopic(['source' => 'industry_digest']);
        $this->assertSame(['#лифтоваяотрасль', '#новостиотрасли', '#лифты', '#MyZip', '#запчастидлялифтов'], $digest->hashtagList());

        $own = new MediaTopic(['source' => 'industry_digest', 'hashtags' => 'Лифты, #новости-рынка  #MyZip']);
        $this->assertSame(['#Лифты', '#новостирынка', '#MyZip', '#запчастидлялифтов'], $own->hashtagList());
    }
}
