<?php

namespace Tests\Unit\Services\Marketing;

use App\Enums\MarketingSection;
use App\Services\Marketing\MarketingReportDocxService;
use Tests\TestCase;

/**
 * Вёрстка ежемесячного отчёта в .docx по форме Приложения № 1.
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
            'main_tasks' => ['Перезапуск товарной кампании', '', 'Сегментация базы рассылки'],
            'sections' => [
                'ads' => ['works' => 'Перебрал группы объявлений', 'changes' => '', 'conclusions' => 'Цена обращения упала'],
                'base' => ['works' => 'Выгрузил базу', 'campaigns' => 'Две рассылки'],
            ],
            'ad_metrics' => ['spend' => '120 000', 'leads' => '48', 'cpl' => '2 500'],
            'next_plan' => ['Запустить фид', ''],
        ];
    }

    public function test_renders_form_header_and_period(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('ЕЖЕМЕСЯЧНЫЙ ОТЧЁТ', $text);
        $this->assertStringContainsString('Приложение № 1', $text);
        $this->assertStringContainsString('Отчётный период: Сентябрь 2026', $text);
        $this->assertStringContainsString('ИП Маркелов', $text);
        $this->assertStringContainsString('ООО «Мой Лифт»', $text);
    }

    public function test_numbers_sections_as_in_the_contract_form(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('1. Основные выполненные задачи', $text);
        $this->assertStringContainsString('2. '.MarketingSection::Ads->label(), $text);
        $this->assertStringContainsString('3. '.MarketingSection::Base->label(), $text);
        $this->assertStringContainsString('9. План и приоритеты на следующий месяц', $text);
        $this->assertStringContainsString('10. Итог', $text);
    }

    public function test_empty_sections_are_omitted(): void
    {
        // п. 4.2 договора: разделы без работ можно не заполнять.
        $text = $this->docText($this->fullForm());

        $this->assertStringNotContainsString(MarketingSection::Social->label(), $text);
        $this->assertStringNotContainsString(MarketingSection::Feedback->label(), $text);
    }

    public function test_blank_task_lines_are_renumbered_without_gaps(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('1. Перезапуск товарной кампании', $text);
        $this->assertStringContainsString('2. Сегментация базы рассылки', $text);
        $this->assertStringNotContainsString('3. Сегментация базы рассылки', $text);
    }

    public function test_ad_metrics_are_labelled_as_in_the_form(): void
    {
        $text = $this->docText($this->fullForm());

        $this->assertStringContainsString('Рекламные расходы, руб.: 120 000', $text);
        $this->assertStringContainsString('Стоимость обращения, руб.: 2 500', $text);
        // Показатель без значения не печатаем.
        $this->assertStringNotContainsString('Показы:', $text);
    }

    public function test_multiline_field_keeps_every_line(): void
    {
        $data = $this->fullForm();
        $data['sections']['ads']['works'] = "15.09 — правил ставки\n17.09 — отключил РСЯ";

        $text = $this->docText($data);

        $this->assertStringContainsString('15.09 — правил ставки', $text);
        $this->assertStringContainsString('17.09 — отключил РСЯ', $text);
    }

    public function test_renders_with_empty_form(): void
    {
        $text = $this->docText([]);

        $this->assertStringContainsString('ЕЖЕМЕСЯЧНЫЙ ОТЧЁТ', $text);
        $this->assertStringContainsString('10. Итог', $text);
    }
}
