<?php

namespace Tests\Unit\Supplier;

use App\Services\AI\OpenAIChatService;
use App\Services\Supplier\SupplierNoteTranslator;
use Tests\TestCase;

/** Примечание к запросу: English-поставщику переводим (один раз на текст), русскому и английский текст — как есть. */
class SupplierNoteTranslatorTest extends TestCase
{
    private function translator(?string $answer, int &$calls): SupplierNoteTranslator
    {
        $ai = new class($answer, $calls) extends OpenAIChatService
        {
            public function __construct(private ?string $answer, private int &$calls) {}

            public function chat(array $messages, string $model = 'gpt-4o', array $options = []): array
            {
                $this->calls++;
                if ($this->answer === null) {
                    throw new \RuntimeException('down');
                }

                return ['content' => $this->answer, 'usage' => [], 'raw' => []];
            }
        };

        return new SupplierNoteTranslator($ai);
    }

    public function test_russian_note_to_an_english_supplier_is_translated_once(): void
    {
        $calls = 0;
        $t = $this->translator('Please send a photo of the nameplate.', $calls);

        $this->assertSame('Please send a photo of the nameplate.', $t->forLanguage('Пришлите фото шильдика.', 'en'));
        $this->assertSame('Please send a photo of the nameplate.', $t->forLanguage('Пришлите фото шильдика.', 'en'));
        $this->assertSame(1, $calls);
    }

    public function test_no_translation_needed(): void
    {
        $calls = 0;
        $t = $this->translator('x', $calls);

        $this->assertSame('Пришлите фото шильдика.', $t->forLanguage('Пришлите фото шильдика.', 'ru'));
        $this->assertSame('Please send a photo.', $t->forLanguage(' Please send a photo. ', 'en'));
        $this->assertSame('', $t->forLanguage(null, 'en'));
        $this->assertSame(0, $calls);
    }
}
