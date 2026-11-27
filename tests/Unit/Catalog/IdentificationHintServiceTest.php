<?php

namespace Tests\Unit\Catalog;

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

    public function test_photo_questions_are_dropped_when_the_item_has_a_photo(): void
    {
        $type = ['key' => 'sensor', 'name' => 'датчик', 'hints' => [
            ['label' => 'Фото маркировки', 'question' => 'Пришлите фото маркировки.'],
            ['label' => 'Где применяется', 'question' => 'Где стоит датчик?'],
        ]];
        $this->assertSame(['Где применяется'], array_column(IdentificationHintService::withoutPhotoHints($type, true)['hints'], 'label'));
        $this->assertCount(2, IdentificationHintService::withoutPhotoHints($type, false)['hints']);
        $this->assertNull(IdentificationHintService::withoutPhotoHints(['key' => 'x', 'name' => 'x', 'hints' => [['label' => 'Фото таблички', 'question' => '…']]], true));
    }

    public function test_a_broken_pattern_is_skipped_not_fatal(): void
    {
        $types = [['key' => 'bad', 'name' => 'x', 'pattern' => '/(/', 'hints' => []], ['key' => 'ok', 'name' => 'y', 'pattern' => '/кнопк/iu', 'hints' => []]];
        $this->assertSame('ok', IdentificationHintService::typeOf('кнопка', $types)['key']);
    }
}
