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

    private function chunked(RequestItemParsingService $svc, string $text): array
    {
        $m = new \ReflectionMethod($svc, 'parseFileTextInChunks');

        return $m->invoke($svc, $text, 'test.xlsx');
    }
}
