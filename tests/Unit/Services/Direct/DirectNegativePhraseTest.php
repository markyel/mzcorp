<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectNegativeService as Negatives;
use PHPUnit\Framework\TestCase;

/**
 * Минус-фраза — самая опасная правка в кампании: ошибка в ней выключает живой
 * трафик молча, без ошибок и предупреждений, и заметить это можно только по
 * отсутствию показов. Поэтому предложение модели проходит проверку.
 */
class DirectNegativePhraseTest extends TestCase
{
    public function test_a_word_from_our_own_world_never_becomes_a_minus(): void
    {
        // Совпадение слова и есть причина мусора: «поручень» бывает у
        // эскалатора и в ванной. Вычесть его — остаться без своих запросов.
        $this->assertNull(Negatives::cleanPhrase('поручень'));
        $this->assertNull(Negatives::cleanPhrase('поручни для ванной'));
        $this->assertNull(Negatives::cleanPhrase('ремень зубчатый'));
        $this->assertNull(Negatives::cleanPhrase('плата'));
        $this->assertNull(Negatives::cleanPhrase('otis'));
    }

    public function test_a_foreign_phrase_passes_cleaned_up(): void
    {
        $this->assertSame('блок питания', Negatives::cleanPhrase('Блок питания'));
        $this->assertSame('материнские', Negatives::cleanPhrase(' Материнские! '));
        $this->assertSame('ванной комнаты', Negatives::cleanPhrase('ванной комнаты'));
    }

    public function test_what_cannot_be_a_minus_phrase_at_all(): void
    {
        $this->assertNull(Negatives::cleanPhrase(null));
        $this->assertNull(Negatives::cleanPhrase(''));
        $this->assertNull(Negatives::cleanPhrase('на'), 'слишком короткое');
        $this->assertNull(
            Negatives::cleanPhrase('регулируемый блок питания распродажа от производителя'),
            'длинная фраза отсекает один запрос, а не класс мусора',
        );
    }
}
