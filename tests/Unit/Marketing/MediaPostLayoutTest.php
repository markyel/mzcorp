<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\MediaPublisherService;
use Tests\TestCase;

/**
 * Вёрстка подборок в ленте: позиция блоком «🔹 название / └ арт. · цена», пустая строка между
 * позициями (19.10.2026: строка «название · арт. M…» переносилась посередине и читалась кашей).
 */
class MediaPostLayoutTest extends TestCase
{
    public function test_new_items_in_telegram(): void
    {
        $in = "<b>Новые позиции в каталоге</b>\n\n🆕 В каталоге появились новые позиции:\n\n"
            ."• Кнопка приказа \"17\", круглая, SJEC · арт. <a href=\"https://x/M34656\">M34656</a>\n"
            ."• Ролик подвеса дверей Meteor D=56мм · арт. <a href=\"https://x/M34662\">M34662</a>\n\n"
            .'{{ORDER_FOOTER}}';

        $out = MediaPublisherService::layoutItems($in, 'telegram', true);

        $this->assertSame("<b>Новые позиции в каталоге</b>\n\n🆕 В каталоге появились новые позиции:\n\n"
            ."🔹 Кнопка приказа \"17\", круглая, SJEC\n└ арт. <a href=\"https://x/M34656\">M34656</a>\n\n"
            ."🔹 Ролик подвеса дверей Meteor D=56мм\n└ арт. <a href=\"https://x/M34662\">M34662</a>\n\n"
            .'{{ORDER_FOOTER}}', $out);
    }

    public function test_price_drop_joins_the_article_line(): void
    {
        $in = "Снизили цены:\n• Отводка Kone AMD01L · арт. <a href=\"u\">M04562</a>\n  8 461 → 1 196 ₽ (−86%)\n• Диод · арт. M19751\n  1 704,24 → 1 448,60 ₽";

        $this->assertSame("Снизили цены:\n\n🔹 Отводка Kone AMD01L\n└ арт. <a href=\"u\">M04562</a> · <s>8 461</s> → <b>1 196 ₽</b> (−86%)\n\n"
            ."🔹 Диод\n└ арт. M19751 · <s>1 704,24</s> → <b>1 448,60 ₽</b>", MediaPublisherService::layoutItems($in, 'max', true));
    }

    public function test_vk_keeps_the_card_address_under_the_item(): void
    {
        $in = "• Отводка Kone · арт. M04562\n  https://www.mylift.ru/?code=M04562\n  8 461 → 1 196 ₽ (−86%)\n• Диод · арт. M19751\n  https://www.mylift.ru/?code=M19751";

        $this->assertSame("🔹 Отводка Kone\n└ арт. M04562 · 8 461 → 1 196 ₽ (−86%)\nhttps://www.mylift.ru/?code=M04562\n\n"
            ."🔹 Диод\n└ арт. M19751\nhttps://www.mylift.ru/?code=M19751", MediaPublisherService::layoutItems($in, 'vk', false));
    }

    public function test_text_without_items_is_left_alone(): void
    {
        $in = "<b>Лифтовая отрасль за неделю</b>\n\n<b>Производство:</b>\n• Завод увеличил выпуск на 30%.";

        $this->assertSame($in, MediaPublisherService::layoutItems($in, 'telegram', true));
    }
}
