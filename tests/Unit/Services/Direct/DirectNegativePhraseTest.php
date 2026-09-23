<?php

namespace Tests\Unit\Services\Direct;

use App\Services\Direct\DirectNegativeService as Negatives;
use PHPUnit\Framework\TestCase;

/**
 * Минус-фраза — самая опасная правка в кампании: ошибка в ней выключает живой
 * трафик молча, без ошибок и предупреждений, и заметить это можно только по
 * отсутствию показов. Поэтому предложение модели проходит проверку.
 *
 * Словарь защищённых основ на проде собирается из каталога; здесь передаём его
 * явно — проверяем само правило, а не содержимое склада.
 */
class DirectNegativePhraseTest extends TestCase
{
    /** Основы, как их отдаёт каталог: по четыре буквы на слово. */
    private array $stems = ['лифт', 'эска', 'пору', 'реме', 'ремн', 'плат', 'масл', 'otis'];

    public function test_a_word_from_our_own_world_never_becomes_a_minus(): void
    {
        // Совпадение слова и есть причина мусора: «поручень» бывает у
        // эскалатора и в ванной. Вычесть его — остаться без своих запросов.
        $this->assertNull(Negatives::cleanPhrase('поручень', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('поручни для ванной', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('ремень зубчатый', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('плата', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('otis', $this->stems));
    }

    public function test_the_stem_covers_other_endings_of_the_same_word(): void
    {
        // Модель предложила «масленки» на запрос «артикул масленки для
        // сервиса», а масленка направляющих лифта у нас есть.
        $this->assertNull(Negatives::cleanPhrase('масленки', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('поручня', $this->stems));
    }

    public function test_a_foreign_phrase_passes_cleaned_up(): void
    {
        $this->assertSame('блок питания', Negatives::cleanPhrase('Блок питания', $this->stems));
        $this->assertSame('материнские', Negatives::cleanPhrase(' Материнские! ', $this->stems));
        $this->assertSame('ванной комнаты', Negatives::cleanPhrase('ванной комнаты', $this->stems));
    }

    public function test_what_cannot_be_a_minus_phrase_at_all(): void
    {
        $this->assertNull(Negatives::cleanPhrase(null, $this->stems));
        $this->assertNull(Negatives::cleanPhrase('', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('на', $this->stems), 'слишком короткое');
        $this->assertNull(
            Negatives::cleanPhrase('регулируемый блок питания распродажа от производителя', $this->stems),
            'длинная фраза отсекает один запрос, а не класс мусора',
        );
    }
}
