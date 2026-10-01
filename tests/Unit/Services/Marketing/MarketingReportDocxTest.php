<?php

namespace Tests\Unit\Services\Marketing;

use App\Enums\MarketingSection;
use App\Services\Marketing\MarketingReportDocxService;
use Tests\TestCase;

/**
 * Вёрстка ежемесячного отчёта в .docx по форме Приложения № 2.
 * Проверяем на собранной форме, без БД: renderData() специально отделён
 * от модели ради этого.
 */
class MarketingReportDocxTest extends TestCase
{
    /** @var array<int, string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $data */
    private function docText(array $data, string $period = 'Сентябрь 2026'): string
    {
        $path = app(MarketingReportDocxService::class)->renderData($data, $period);
        $this->tmp[] = $path;
        $this->assertFileExists($path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'docx должен открываться как zip');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        // Текст разбит на run'ы — склеиваем, чтобы искать по содержимому.
        return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function fullForm(): array
    {
        return [
            'requisites' => [
                'contract_number' => '17',
                'contract_date' => '«01» октября 2026 г.',
                'contractor' => 'ИП Маркелов',
                'customer' => 'ООО «Мой Лифт»',
            ],
            'regular' => [
                'ads' => ['status' => 'done', 'comment' => "Перебрал группы объявлений\nПоказатели: Рекламные расходы, руб.: 120 000."],
                'base' => ['status' => 'done', 'comment' => 'Выгрузил базу'],
                'social' => ['status' => 'not_needed', 'comment' => ''],
            ],
            'projects' => [
                ['task' => 'Перезапуск Директа на своём аккаунте', 'stage' => 'Выполнено', 'result' => 'Три кампании'],
                ['task' => '', 'stage' => '', 'result' => ''],
            ],
            'conclusions' => 'Новые клиенты растут',
            'next_tasks' => ['Запустить канал в MAX', ''],
        ];
    }

    public function test_renders_form_header_and_period(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('ЕЖЕМЕСЯЧНЫЙ ОТЧЁТ ОБ ОКАЗАННЫХ УСЛУГАХ', $text);
        $this->assertStringContainsString('Приложение № 2', $text);
        $this->assertStringContainsString('к Договору № 17 от «01» октября 2026 г.', $text);
        $this->assertStringContainsString('Отчётный период: Сентябрь 2026', $text);
    }

    public function test_every_regular_direction_is_listed_with_a_status(): void
    {
        // п. 4.3: направление без изменений — не отсутствие услуги, строка есть всегда.
        $text = $this->docText($this->fullForm());

        foreach (MarketingSection::ordered() as $section) {
            $this->assertStringContainsString($section->label(), $text);
        }
        $this->assertStringContainsString('Выполнялось', $text);
        $this->assertStringContainsString('Не требовалось', $text);
        $this->assertStringContainsString('Перебрал группы объявлений', $text);
        $this->assertStringContainsString('Показатели: Рекламные расходы, руб.: 120 000.', $text);
    }

    public function test_project_tasks_skip_blank_rows(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('2. Дополнительные (проектные) задачи', $text);
        $this->assertStringContainsString('Перезапуск Директа на своём аккаунте', $text);
        $this->assertStringContainsString('Три кампании', $text);
    }

    public function test_project_section_is_omitted_without_projects(): void
    {
        $data = $this->fullForm();
        $data['projects'] = [];

        $this->assertStringNotContainsString('Дополнительные (проектные) задачи', $this->docText($data));
    }

    public function test_conclusions_and_next_tasks(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('3. Основные выводы и рекомендации', $text);
        $this->assertStringContainsString('Новые клиенты растут', $text);
        $this->assertStringContainsString('4. Задачи, переходящие на следующий период', $text);
        $this->assertStringContainsString('1. Запустить канал в MAX', $text);
        $this->assertStringNotContainsString('2. ', $this->afterLast($text, '4. Задачи'));
    }

    public function test_renders_with_empty_form(): void
    {
        $text = $this->docText([]);

        $this->assertStringContainsString('ЕЖЕМЕСЯЧНЫЙ ОТЧЁТ', $text);
        $this->assertStringContainsString(MarketingSection::Analytics->label(), $text);
        $this->assertStringContainsString('Не требовалось', $text);
    }

    private function afterLast(string $text, string $marker): string
    {
        $pos = mb_strrpos($text, $marker);

        return $pos === false ? '' : mb_substr($text, $pos + mb_strlen($marker));
    }
}
