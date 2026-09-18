<?php

namespace Tests\Unit\Services\Request;

use App\Services\Request\ItemTokenizer;
use Tests\TestCase;

/**
 * Токены артикулов для sticky-назначения. Эталонный кейс — пара заявок
 * 18.09.2026: клиент написал «MLKAT-X (VER-1)», площадка прислала
 * «MLKAT-X VER-1», точное сравнение строк их не связало, и парные заявки
 * ушли разным менеджерам.
 */
class ItemTokenizerTest extends TestCase
{
    public function test_normalization_ignores_spacing_and_punctuation(): void
    {
        $this->assertSame('MLKATXVER1', ItemTokenizer::normalize('MLKAT-X (VER-1)'));
        $this->assertSame('MLKATXVER1', ItemTokenizer::normalize('mlkat-x ver-1'));
        $this->assertSame('MLKATXVER1', ItemTokenizer::normalize(' MLKAT_X/VER.1 '));
    }

    public function test_cyrillic_lookalikes_become_latin(): void
    {
        // «М» кириллическая и «M» латинская на вид неотличимы, а в заявках
        // встречаются обе.
        $this->assertSame('M22456', ItemTokenizer::normalize('М22456'));
        $this->assertSame(ItemTokenizer::normalize('САN1Х'), ItemTokenizer::normalize('CAN1X'));
    }

    public function test_only_distinctive_tokens_pass(): void
    {
        $this->assertTrue(ItemTokenizer::isDistinctive('CAN1X'));
        $this->assertTrue(ItemTokenizer::isDistinctive('MLKATXVER1'));

        // Короткое, без цифр или без букв — матчить по такому нельзя.
        $this->assertFalse(ItemTokenizer::isDistinctive('VER1'));
        $this->assertFalse(ItemTokenizer::isDistinctive('MLKATX'));
        $this->assertFalse(ItemTokenizer::isDistinctive('20260918'));
        $this->assertFalse(ItemTokenizer::isDistinctive(''));
    }

    public function test_dimensions_are_not_tokens(): void
    {
        // На сухом прогоне по размерам связывались заведомо разные заявки:
        // вкладыш L100мм и совсем другой вкладыш той же длины.
        $this->assertFalse(ItemTokenizer::isDistinctive('800MM'));
        $this->assertFalse(ItemTokenizer::isDistinctive('L100MM'));
        $this->assertFalse(ItemTokenizer::isDistinctive('L140MM'));
        $this->assertFalse(ItemTokenizer::isDistinctive('D15MM'));
        $this->assertFalse(ItemTokenizer::isDistinctive('24V'));
    }

    public function test_our_catalog_article_always_counts(): void
    {
        // M-артикул — одна буква и цифры, общее правило «две буквы» его бы
        // отсекло, а это самый точный сигнал «та же позиция».
        $this->assertTrue(ItemTokenizer::isDistinctive('M05186'));
        $this->assertTrue(ItemTokenizer::isDistinctive('M00343'));
        $this->assertFalse(ItemTokenizer::isDistinctive('M123'));
    }

    public function test_real_part_numbers_pass(): void
    {
        // Проверены на проде: по ним заявки связались верно.
        foreach (['ZAA717AP1', '62102RS1', 'BFK16', 'HTD5M65'] as $token) {
            $this->assertTrue(ItemTokenizer::isDistinctive($token), $token);
        }
    }

    public function test_tokens_of_the_real_pair_overlap(): void
    {
        $direct = ItemTokenizer::tokensFor([
            (object) [
                'parsed_article' => 'MLKAT-X (VER-1)',
                'parsed_name' => 'Соединительная плата Mikrolift MLKAT-X (VER-1), шинный модуль CAN1X',
            ],
        ]);
        $marketplace = ItemTokenizer::tokensFor([
            (object) ['parsed_article' => 'MLKAT-X VER-1', 'parsed_name' => 'Соединительная плата Mikrolift MLKAT-X VER-1'],
            (object) ['parsed_article' => 'CAN1X', 'parsed_name' => 'Шинный модуль CAN1X'],
        ]);

        $this->assertContains('MLKATXVER1', $direct);
        $this->assertContains('CAN1X', $direct, 'артикул второй позиции лежит внутри названия первой');
        $this->assertNotEmpty(array_intersect($direct, $marketplace));
    }

    public function test_tokens_are_unique_and_capped(): void
    {
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = (object) ['parsed_article' => "ART{$i}0X", 'parsed_name' => "Позиция ART{$i}0X"];
        }
        $tokens = ItemTokenizer::tokensFor($items);

        $this->assertLessThanOrEqual(ItemTokenizer::MAX_TOKENS, count($tokens));
        $this->assertSame(array_values(array_unique($tokens)), $tokens);
    }

    public function test_items_without_articles_give_no_tokens(): void
    {
        $tokens = ItemTokenizer::tokensFor([
            (object) ['parsed_article' => null, 'parsed_name' => 'Ролик направляющий резиновый'],
        ]);

        $this->assertSame([], $tokens);
    }

    public function test_sql_expression_mirrors_php_normalization(): void
    {
        $sql = ItemTokenizer::sqlNormalize('request_items.parsed_article');

        $this->assertStringContainsString('upper(translate(coalesce(request_items.parsed_article', $sql);
        $this->assertStringContainsString("'[^A-Z0-9]'", $sql);
    }
}
