<?php

namespace Tests\Unit\Services;

use App\Services\AI\OpenAIChatService;
use App\Services\RequestItemParsingService;
use Tests\TestCase;

/**
 * Длинный текст файла разбирается частями: ТЗ ДМА на 190 строк (MZ2026-00483) одним
 * вызовом давало 60 позиций — модель обрывала перечень.
 */
class FileTextChunkingTest extends TestCase
{
    public function test_long_table_is_parsed_in_chunks_with_the_header_and_no_row_is_lost(): void
    {
        $fake = new class extends OpenAIChatService
        {
            public array $prompts = [];

            public function chat(array $messages, string $model = 'gpt-4o', array $options = []): array
            {
                $user = (string) end($messages)['content'];
                $this->prompts[] = $user;
                preg_match_all('/^Позиция (\d+) \|/mu', $user, $m);
                $items = array_map(fn ($n) => ['name' => 'Позиция '.$n, 'article' => 'A'.$n, 'qty' => 1, 'unit' => 'шт'], $m[1]);

                return ['content' => json_encode(['items' => $items]), 'usage' => [], 'raw' => []];
            }
        };

        $rows = ['Наименование | Ед. | Кол-во'];
        for ($i = 1; $i <= 190; $i++) {
            $rows[] = "Позиция {$i} | шт | 1";
        }

        $items = $this->chunked(new RequestItemParsingService($fake), implode("\n", $rows));

        $this->assertCount(190, $items);
        $this->assertCount(7, $fake->prompts);
        foreach ($fake->prompts as $prompt) {
            $this->assertStringContainsString('Наименование | Ед. | Кол-во', $prompt);
        }
    }

    public function test_short_text_goes_in_one_call(): void
    {
        $fake = new class extends OpenAIChatService
        {
            public int $calls = 0;

            public function chat(array $messages, string $model = 'gpt-4o', array $options = []): array
            {
                $this->calls++;

                return ['content' => json_encode(['items' => [['name' => 'Ролик', 'qty' => 2, 'unit' => 'шт']]]), 'usage' => [], 'raw' => []];
            }
        };

        $items = $this->chunked(new RequestItemParsingService($fake), "Наименование | Кол-во\nРолик | 2");

        $this->assertSame(1, $fake->calls);
        $this->assertCount(1, $items);
    }

    public function test_same_list_in_two_files_keeps_the_fuller_one_and_long_lists_skip_the_llm_merge(): void
    {
        $fake = new class extends OpenAIChatService
        {
            public int $calls = 0;

            public function chat(array $messages, string $model = 'gpt-4o', array $options = []): array
            {
                $this->calls++;

                return ['content' => json_encode(['items' => []]), 'usage' => [], 'raw' => []];
            }
        };
        $svc = new RequestItemParsingService($fake);

        $items = [];
        for ($i = 1; $i <= 50; $i++) {
            $items[] = ['name' => "Позиция {$i}", 'article' => "ART-{$i}", 'qty' => 1, 'unit' => 'шт.', '__source' => 'xlsx_attachment_2'];
        }
        for ($i = 1; $i <= 45; $i++) {
            $items[] = ['name' => "Позиция {$i} (из docx)", 'article' => "ART {$i}", 'qty' => 1, 'unit' => 'шт.', '__source' => 'docx_attachment_1'];
        }
        $items[] = ['name' => 'Ролик', 'article' => 'R-1', 'qty' => 2, 'unit' => 'шт.', '__source' => 'pdf_attachment_3'];

        $m = new \ReflectionMethod($svc, 'consolidateSplitItems');
        $out = $m->invoke($svc, $items, 'ТЗ', '', null);

        $this->assertCount(51, $out); // 50 из xlsx + отдельный pdf; docx-повтор отброшен
        $this->assertSame(0, $fake->calls); // > 40 позиций — без LLM-консолидатора
        foreach ($out as $it) {
            $this->assertEquals((float) ($it['article'] === 'R-1' ? 2 : 1), (float) ($it['__qty_summed'] ?? $it['qty'])); // количество не удвоено
        }
    }

    public function test_rows_of_one_file_with_the_same_article_but_other_numbers_stay_apart(): void
    {
        $svc = new RequestItemParsingService(new OpenAIChatService());
        $src = 'xlsx_attachment_1';
        $items = [
            ['name' => 'Предохранитель D02 (желтый), 25 А, 400 В', 'article' => 'D02', 'qty' => 5, '__source' => $src],
            ['name' => 'Предохранитель D02 (белый), 35 А, 400 В', 'article' => 'D02', 'qty' => 5, '__source' => $src],
            ['name' => 'Ролик RT100 башмака', 'article' => '60730002069L', 'qty' => 12, '__source' => $src],
            ['name' => 'Ролик RT100 башмака', 'article' => '60730002069L', 'qty' => 12, '__source' => $src],
            // Разные источники — правило прежнее: склеиваются по артикулу.
            ['name' => 'Плата VQC 1', 'article' => 'OA85', 'qty' => 1, '__source' => 'vision_photo'],
            ['name' => 'Плата VQC 2', 'article' => 'OA85', 'qty' => 1, '__source' => 'email_body'],
        ];

        $out = (new \ReflectionMethod($svc, 'dedupeWithinList'))->invoke($svc, $items);
        $names = array_column($out, 'name');

        $this->assertContains('Предохранитель D02 (желтый), 25 А, 400 В', $names);
        $this->assertContains('Предохранитель D02 (белый), 35 А, 400 В', $names);
        $this->assertCount(4, $out);
        $roller = array_values(array_filter($out, fn ($i) => $i['article'] === '60730002069L'))[0];
        $this->assertEquals(24, $roller['__qty_summed']);
    }

    /** 13.11.2026, MZ2026-01489: вилка и розетка по одному ТУ слиплись в вилку на 20 шт. */
    public function test_a_standard_number_is_not_an_article_for_the_merge(): void
    {
        $svc = new RequestItemParsingService(new OpenAIChatService());
        $items = [
            ['name' => 'Вилка РП10-7ЛП БРО.364.025. ТУ', 'article' => 'БРО.364.025. ТУ', 'qty' => 10, '__source' => 'email_body'],
            ['name' => 'Розетка РП10-7ЛП БРО.364.025 ТУ', 'article' => 'БРО.364.025 ТУ', 'qty' => 10, '__source' => 'email_body'],
            ['name' => 'Вилка ГРПМ 1-31ШУ2-В', 'article' => 'НЩО.364.006ТУ', 'qty' => 10, '__source' => 'email_body'],
            ['name' => 'Вилка ГРПМ 1-31ШУ2-В', 'article' => 'НЩО.364.006ТУ', 'qty' => 5, '__source' => 'email_body'],
            // Обычный артикул со словом-фрагментом «ТУ» внутри — по-прежнему артикул.
            ['name' => 'Кнопка', 'article' => 'ТУБ-12', 'qty' => 1, '__source' => 'email_body'],
            ['name' => 'Кнопка вызова', 'article' => 'ТУБ-12', 'qty' => 1, '__source' => 'email_body'],
        ];

        $out = (new \ReflectionMethod($svc, 'dedupeWithinList'))->invoke($svc, $items);
        $byName = array_column($out, null, 'name');

        $this->assertCount(4, $out);
        $this->assertEquals(10, $byName['Вилка РП10-7ЛП БРО.364.025. ТУ']['__qty_summed'] ?? $byName['Вилка РП10-7ЛП БРО.364.025. ТУ']['qty']);
        $this->assertArrayHasKey('Розетка РП10-7ЛП БРО.364.025 ТУ', $byName);
        // Та же строка дважды — склеивается по названию, как раньше по артикулу.
        $this->assertEquals(15, $byName['Вилка ГРПМ 1-31ШУ2-В']['__qty_summed']);
        $this->assertEquals(2, $byName['Кнопка']['__qty_summed']);
    }

    public function test_a_new_row_with_the_same_standard_number_is_not_taken_for_an_existing_item(): void
    {
        $svc = new RequestItemParsingService(new OpenAIChatService());
        $existing = collect([new \App\Models\RequestItem([
            'parsed_name' => 'Вилка РП10-7ЛП БРО.364.025. ТУ', 'parsed_article' => 'БРО.364.025. ТУ', 'is_active' => true,
        ])]);
        $dup = new \ReflectionMethod($svc, 'isDuplicate');

        $this->assertFalse($dup->invoke($svc, ['name' => 'Розетка РП10-7ЛП БРО.364.025 ТУ', 'article' => 'БРО.364.025 ТУ'], $existing));
        $this->assertTrue($dup->invoke($svc, ['name' => 'Вилка РП10-7ЛП БРО.364.025 ТУ', 'article' => 'БРО.364.025 ТУ'], $existing));
    }

    private function chunked(RequestItemParsingService $svc, string $text): array
    {
        $m = new \ReflectionMethod($svc, 'parseFileTextInChunks');

        return $m->invoke($svc, $text, 'test.xlsx');
    }
}
