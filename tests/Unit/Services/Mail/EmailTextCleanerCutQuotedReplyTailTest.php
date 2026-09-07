<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\EmailTextCleanerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EmailTextCleanerService::cutQuotedReplyTail — собственный текст автора
 * исходящего письма без цитаты ответа (любой формат клиента).
 * Регресс M-2026-14608.
 */
class EmailTextCleanerCutQuotedReplyTailTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_cut_quoted_reply_tail(string $input, string $expected): void
    {
        $this->assertSame($expected, (new EmailTextCleanerService())->cutQuotedReplyTail($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'Apple Mail / Yandex attribution + > block' => [
            "Уточните модель.\n\n4 сент. 2026 г., в 09:43, Клиент <c@x.ru> написал(а):\n> Прошу счёт\n> Реквизиты\n",
            'Уточните модель.',
        ];
        yield 'пишет: attribution' => [
            "Не наша номенклатура.\r\n\r\n12.05.2026 14:32, Иван Иванов <ivan@x.ru> пишет:\r\n> Запрашиваем коммерческое предложение\r\n",
            'Не наша номенклатура.',
        ];
        yield 'Original Message marker' => [
            "Счёт во вложении.\n-----Original Message-----\nFrom: c@x.ru\nSent: today\nПрошу счёт\n",
            'Счёт во вложении.',
        ];
        yield 'Outlook header block without > prefix' => [
            "Счёт во вложении.\n\nОт: Клиент <c@x.ru>\nОтправлено: 4 сентября 2026 г. 9:43\nКому: info@myzip.ru\nТема: Запрос\n\nПрошу счёт\n",
            'Счёт во вложении.',
        ];
        yield 'trailing > block without attribution' => [
            "Ок, сделаем.\n\n> Прошу счёт\n> на 5 шт\n",
            'Ок, сделаем.',
        ];
        yield 'inline replies between quotes are kept (quote is not dominant)' => [
            "> Какой срок?\nДве недели.\n> Какая цена?\nУточню.\n",
            "> Какой срок?\nДве недели.\n> Какая цена?\nУточню.",
        ];
        yield 'M-2026-12166: mail.ru quote in the middle, footer after it' => [
            "Добрый день!\nПодскажите а с резьбой М10мм есть?\n\n> \n> Понедельник, 24 августа 2026, 00:01 +03:00 от Агрызков Сергей <sergey.agryzkov@myzip.ru\n> >:\n> \n> Здравствуйте, Андрей Никоноров!\n> Мы отправляли вам коммерческое предложение *364274*.\n> Подскажите, готовы ли вы перейти к выставлению счёта.\n\n--\nОтправлено из мобильной почты\n",
            "Добрый день!\nПодскажите а с резьбой М10мм есть?",
        ];
        yield 'mail.ru attribution without > prefix' => [
            "Спасибо, всё понятно.\n\nПонедельник, 24 августа 2026, 00:01 +03:00 от Агрызков Сергей <s@myzip.ru>:\nЗдравствуйте!\nМы отправляли КП 364274.\n",
            'Спасибо, всё понятно.',
        ];
        yield 'bare От: line without header block is not a cut' => [
            "От: нас ничего не требуется, счёт ниже.\nСчёт №5 во вложении.\n",
            "От: нас ничего не требуется, счёт ниже.\nСчёт №5 во вложении.",
        ];
        yield 'whole body is a quote → empty' => [
            "> Прошу счёт\n> Реквизиты\n",
            '',
        ];
        yield 'empty' => ['   ', ''];
    }
}
