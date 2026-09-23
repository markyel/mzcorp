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

    public function test_our_own_word_alone_never_becomes_a_minus(): void
    {
        // Совпадение слова и есть причина мусора: «поручень» бывает у
        // эскалатора и в ванной. Вычесть его — остаться без своих запросов.
        $this->assertNull(Negatives::cleanPhrase('поручень', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('плата', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('ремень зубчатый', $this->stems), 'оба слова наши');
    }

    public function test_the_core_of_our_world_is_forbidden_even_in_a_pair(): void
    {
        // Минус-фраза «лифт запчасти» выключила бы ровно то, ради чего реклама
        // и существует, хотя «запчасти» само по себе слово не наше.
        $this->assertNull(Negatives::cleanPhrase('лифт запчасти', $this->stems));
        $this->assertNull(Negatives::cleanPhrase('otis каталог', $this->stems));
    }

    public function test_a_pair_where_only_one_word_is_ours_is_allowed(): void
    {
        // «Блок» у нас есть (блок управления), «блок питания» — нет: вместе
        // эти слова описывают чужой товар, а минус-фраза требует обоих сразу.
        $this->assertSame('блок питания', Negatives::cleanPhrase('блок питания', ['блок', 'плат']));
        $this->assertSame('поручни для ванной', Negatives::cleanPhrase('поручни для ванной', $this->stems));
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
