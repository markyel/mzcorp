<?php

namespace Tests\Unit\Support;

use App\Support\ClientText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Обращение и мелочи текста в письмах клиентам. Примеры имён — реальные
 * значения client_name из заявок за сентябрь–октябрь 2026.
 */
class ClientTextTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function names(): array
    {
        return [
            'имя и фамилия' => ['Игорь Тюренков', 'Игорь Тюренков'],
            'кавычки из From' => ['"Alexey Alpatsky"', 'Alexey Alpatsky'],
            'маленькие буквы' => ['nikita ogienko', 'Nikita Ogienko'],
            'ФИО → имя-отчество' => ['Ворона Николай Николаевич', 'Николай Николаевич'],
            'только имя' => ['Ella', 'Ella'],
            'отдел закупок' => ['Liftway.ru — [ЗАКУПКИ]', null],
            'организация СП' => ['СП Евролифт', null],
            'ТСЖ' => ['ТСЖ Южный каскад', null],
            'инициалы с точками' => ['Курбангалиев Р.А.', null],
            'адрес вместо имени' => ['info@revator.ru', null],
            'пусто' => [null, null],
            'одна буква' => ['A', null],
        ];
    }

    #[DataProvider('names')]
    public function test_person_name(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, ClientText::personName($raw));
    }

    public function test_greeting_without_name_has_no_comma(): void
    {
        $this->assertSame('Здравствуйте!', ClientText::greeting('Liftway.ru — [ЗАКУПКИ]'));
        $this->assertSame('Здравствуйте, Игорь Тюренков!', ClientText::greeting('Игорь Тюренков'));
    }

    public function test_days_plural(): void
    {
        $this->assertSame('1 день', ClientText::days(1));
        $this->assertSame('3 дня', ClientText::days(3));
        $this->assertSame('7 дней', ClientText::days(7));
        $this->assertSame('11 дней', ClientText::days(11));
        $this->assertSame('21 день', ClientText::days(21));
        $this->assertSame('24 дня', ClientText::days(24));
    }

    public function test_expand_days_in_template_output(): void
    {
        $this->assertSame(
            'по заявке M-1 (7 дней назад). Истекает через 3 дня',
            ClientText::expandDays('по заявке M-1 (7 дн. назад). Истекает через 3 дн.'),
        );
        $this->assertSame(
            "Отправили 2 дня назад.\n\nПодскажите",
            ClientText::expandDays("Отправили 2 дн. назад.\n\nПодскажите"),
        );
        $this->assertSame(
            'прошло 5 дней. Напишите нам',
            ClientText::expandDays('прошло 5 дн. Напишите нам'),
        );
    }

    public function test_percent_uses_comma(): void
    {
        $this->assertSame('12,5%', ClientText::percent(12.5));
        $this->assertSame('10%', ClientText::percent(10.0));
    }

    public function test_list_item_escapes_markdown(): void
    {
        $this->assertSame('- Ролик 100\*50 \_new\_', ClientText::listItem("  Ролик 100*50\n _new_ "));
    }
}
