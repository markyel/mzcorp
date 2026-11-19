<?php

namespace Tests\Unit\Services\Supplier;

use App\Prompts\Suppliers\TranslateItemNamesPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Supplier\SupplierItemTranslator;
use Mockery;
use Tests\TestCase;

/**
 * Английское название позиции для иностранного поставщика — термином отрасли, а не дословно.
 * M-2026-20451 (27.10.2026): «Канатоведущий шкив (Обод КВШ KONE, … 6 лунок, d=13мм лебедка MX20)»
 * ушло в QuoteParts как «Cable guiding sheave (Rim KVS KONE, … 6 holes, … winch MX20)».
 */
class SupplierItemTranslatorTest extends TestCase
{
    private const SHEAVE = 'Канатоведущий шкив (Обод КВШ KONE, U-образная канавка, D=600 мм, 6 лунок, d=13мм лебедка MX20)';

    public function test_the_model_gets_the_trade_terms_and_the_reference_example(): void
    {
        $system = (string) (new TranslateItemNamesPrompt())->build([['id' => 1, 'name' => self::SHEAVE]])[0]['content'];

        $this->assertStringContainsString('NEVER transliterated', $system);
        $this->assertStringContainsString('КВШ → traction sheave', $system);
        $this->assertStringContainsString('«6 лунок» → 6 grooves', $system);
        $this->assertStringContainsString('ограничитель скорости, ОС → overspeed governor', $system);
        $this->assertStringContainsString('кабина → car', $system);
        $this->assertStringContainsString('«Traction sheave D=600 mm, d=13 mm, 6 grooves, U-shaped groove, gearless machine MX20»', $system);
    }

    public function test_names_are_translated_by_the_configured_model(): void
    {
        config(['services.openai.item_translation_model' => 'gpt-4.1']);
        $openai = Mockery::mock(OpenAIChatService::class);
        $openai->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages, string $model) => $model === 'gpt-4.1' && str_contains($messages[1]['content'], 'лунок'))
            ->andReturn(['content' => json_encode(['items' => [['id' => 43729, 'name_en' => 'Traction sheave D=600 mm, d=13 mm, 6 grooves, U-shaped groove, gearless machine MX20']]])]);

        $names = (new SupplierItemTranslator($openai))->translate([43729 => self::SHEAVE, 5 => '  ']);

        $this->assertSame([43729 => 'Traction sheave D=600 mm, d=13 mm, 6 grooves, U-shaped groove, gearless machine MX20'], $names);
    }

    public function test_an_answer_under_the_input_key_is_taken(): void
    {
        // Qwen через RouterAI отвечает «name» вместо «name_en» (19.11.2026, T-DNVFSU); русский ответ — не перевод.
        $openai = Mockery::mock(OpenAIChatService::class);
        $openai->shouldReceive('chat')->once()->andReturn(['content' => json_encode(['items' => [
            ['id' => 34403, 'name' => 'Landing door lock MS 42 P (S1 a), triangular key, red indicator light'],
        ]])]);

        $this->assertSame([34403 => 'Landing door lock MS 42 P (S1 a), triangular key, red indicator light'],
            (new SupplierItemTranslator($openai))->translate([34403 => 'Ключевина (замок) MS 42 P (S1 a), ключ треуголный, красная подсветка']));
    }

    public function test_a_broken_answer_is_asked_again(): void
    {
        // 19.11.2026: сбойный ответ модели — названия ушли поставщикам по-русски (MZ2026-02350).
        $openai = Mockery::mock(OpenAIChatService::class);
        $openai->shouldReceive('chat')->twice()->andReturn(
            ['content' => 'not json'],
            ['content' => json_encode(['items' => [['id' => 7, 'name_en' => 'Landing door lock MS 42 P (S1 a), triangular key, red indicator light']]])],
        );

        $names = (new SupplierItemTranslator($openai))->translate([7 => 'Ключевина (замок) MS 42 P (S1 a), ключ треуголный, красная подсветка']);

        $this->assertSame([7 => 'Landing door lock MS 42 P (S1 a), triangular key, red indicator light'], $names);
    }
}
