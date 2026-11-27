<?php

namespace Tests\Unit\Catalog;

use App\Livewire\Catalog\IdentificationHints;
use App\Services\Catalog\IdentificationHintService;
use Tests\TestCase;

/** Правило распознавания типа на странице справочника: начала слов через запятую или регулярное выражение. */
class IdentificationHintsPatternTest extends TestCase
{
    public function test_words_become_a_word_start_pattern(): void
    {
        $pattern = IdentificationHints::pattern('Шкив, блок отвод');
        $this->assertSame('/(?<!\p{L})(?:шкив|блок отвод)/iu', $pattern);
        $types = [['key' => 'x', 'name' => 'x', 'pattern' => $pattern, 'hints' => []]];
        $this->assertSame('x', IdentificationHintService::typeOf('Блок отводной 450 мм', $types)['key']);
        $this->assertNull(IdentificationHintService::typeOf('Разблокировка двери', $types));
    }

    public function test_a_regex_is_kept_and_a_broken_one_rejected(): void
    {
        $this->assertSame('/отводк/iu', IdentificationHints::pattern(' /отводк/iu '));
        $this->assertNull(IdentificationHints::pattern('/(/'));
        $this->assertNull(IdentificationHints::pattern(' , '));
    }
}
