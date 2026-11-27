<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\IdentificationCoverageService;
use App\Services\Catalog\IdentificationHintService;
use Tests\TestCase;

/** Тип детали для подсказки «что нужно для подбора»: главное слово названия, частное раньше общего. */
class IdentificationHintServiceTest extends TestCase
{
    private function type(string $name): ?string
    {
        return IdentificationHintService::typeOf($name, IdentificationHintService::seedTypes())['key'] ?? null;
    }

    public function test_the_head_word_decides(): void
    {
        $this->assertSame('board', $this->type('Плата LOPCTA2.QB вызывного поста Schindler на 2 кнопки'));
        $this->assertSame('button', $this->type('Кнопка приказа KLEEMANN FMR BS (4 двухконт.разъема)'));
        $this->assertSame('chain', $this->type('Цепь привода поручня эскалатора двухрядная'));
        $this->assertSame('handrail_wheel', $this->type('Колесо привода поручня эскалатора Otis D557'));
        $this->assertSame('drive_electric', $this->type('Двигатель привода дверей OPR500'));
        $this->assertSame('roller', $this->type('Ролик натяжной цепи'));
    }

    public function test_coupler_and_diverter_and_sheave_are_told_apart(): void
    {
        $this->assertSame('door_coupler', $this->type('Отводка дверей лифта Otis Gen2'));
        $this->assertSame('diverter', $this->type('Отводной блок 450мм, 4 ручья'));
        $this->assertSame('sheave', $this->type('Канатоведущий шкив лебедки Ø240'));
        $this->assertSame('rope', $this->type('Канат DIN, диаметр 8 мм'));
        $this->assertSame('contactor', $this->type('Реле промежуточное РЭП 34'));
        $this->assertSame('door_lock', $this->type('Замок ДШ OBM K01 левый'));
    }

    public function test_unknown_part_gets_no_hint(): void
    {
        $this->assertNull($this->type('Масленка направляющей кабины ETN 170'));
        $this->assertNull($this->type(''));
    }

    public function test_chain_asks_first_which_chain_it_is(): void
    {
        $hint = IdentificationHintService::typeOf('Цепь 16B-2', IdentificationHintService::seedTypes());
        $this->assertSame('Какая цепь и где стоит', $hint['hints'][0]['label']);
    }

    public function test_only_what_is_not_known_is_asked_and_partial_as_a_confirmation(): void
    {
        $hints = [
            ['id' => 1, 'label' => 'Фото маркировки', 'question' => 'Пришлите фото маркировки.'],
            ['id' => 2, 'label' => 'Где применяется', 'question' => 'Где стоит датчик?'],
            ['id' => 3, 'label' => 'Напряжение', 'question' => 'Какое напряжение?'],
        ];
        $merged = IdentificationCoverageService::merge($hints, [
            1 => ['status' => 'known', 'value' => 'YG-30s', 'source' => 'photo'],
            2 => ['status' => 'missing'],
            3 => ['status' => 'partial', 'value' => '250V?', 'source' => 'photo', 'ask' => 'Подтвердите напряжение 250 В.'],
        ]);
        $this->assertSame(['known', 'missing', 'partial'], array_column($merged, 'status'));
        $this->assertSame('YG-30s', $merged[0]['value']);

        $ask = IdentificationHintService::toAsk(['hints' => $merged]);
        $this->assertSame(['Где применяется', 'Напряжение'], array_column($ask, 'label'));
        $this->assertSame('Подтвердите напряжение 250 В.', $ask[1]['question']);
    }

    public function test_unclear_answers_do_not_hide_a_question(): void
    {
        $hints = [['label' => 'Фото маркировки', 'question' => 'Пришлите фото маркировки.'], ['label' => 'Сторона', 'question' => 'Левая или правая?']];
        $merged = IdentificationCoverageService::merge($hints, [1 => ['status' => 'known', 'value' => ''], 2 => ['status' => 'maybe']]);
        $this->assertSame(['partial', 'missing'], array_column($merged, 'status'));
        $this->assertCount(2, IdentificationHintService::toAsk(['hints' => $merged]));
    }

    public function test_a_broken_pattern_is_skipped_not_fatal(): void
    {
        $types = [['key' => 'bad', 'name' => 'x', 'pattern' => '/(/', 'hints' => []], ['key' => 'ok', 'name' => 'y', 'pattern' => '/кнопк/iu', 'hints' => []]];
        $this->assertSame('ok', IdentificationHintService::typeOf('кнопка', $types)['key']);
    }
}
