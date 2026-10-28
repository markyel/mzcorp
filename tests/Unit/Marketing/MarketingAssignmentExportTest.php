<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingAssignment;
use App\Models\User;
use App\Services\Marketing\MarketingAssignmentExportService;
use Tests\TestCase;

/**
 * Выгрузка аутсорс-задачи соседнему отделу: исполнитель без доступа в MyLift
 * получает инструкцию в PDF/HTML и материалы архивом.
 */
class MarketingAssignmentExportTest extends TestCase
{
    private function assignment(): MarketingAssignment
    {
        $a = new MarketingAssignment([
            'title' => 'Страница 404 / в стиле сайта',
            'executor' => 'Веб-отдел',
            'priority' => 1,
            'instructions' => "## Что сделать\n\n1. Сверстать страницу\n2. Отдавать код **404**\n\n<script>alert(1)</script>",
        ]);
        $a->id = 7;
        $a->setRelation('files', collect());
        $a->setRelation('author', new User(['name' => 'Маркетолог', 'email' => 'mark@myzip.ru']));

        return $a;
    }

    public function test_instructions_render_markdown_and_escape_raw_html(): void
    {
        $html = app(MarketingAssignmentExportService::class)->instructionsHtml($this->assignment());

        $this->assertStringContainsString('<h2>Что сделать</h2>', $html);
        $this->assertStringContainsString('<strong>404</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_html_export_carries_executor_priority_and_author(): void
    {
        $html = app(MarketingAssignmentExportService::class)->html($this->assignment());

        $this->assertStringContainsString('Аутсорс-задача № 7', $html);
        $this->assertStringContainsString('Веб-отдел', $html);
        $this->assertStringContainsString('высокий', $html);
        $this->assertStringContainsString('mark@myzip.ru', $html);
    }

    public function test_pdf_and_zip_are_built(): void
    {
        $service = app(MarketingAssignmentExportService::class);
        $a = $this->assignment();

        $this->assertStringStartsWith('%PDF', $service->pdf($a));

        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($tmp, $service->zip($a));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertNotFalse($zip->locateName('Инструкция.pdf'));
        $this->assertNotFalse($zip->locateName('Инструкция.html'));
        $zip->close();
        @unlink($tmp);
    }

    public function test_filename_has_no_path_separators(): void
    {
        $name = app(MarketingAssignmentExportService::class)->filename($this->assignment(), 'zip');

        $this->assertSame('Аутсорс-задача 7 — Страница 404 _ в стиле сайта.zip', $name);
    }
}
