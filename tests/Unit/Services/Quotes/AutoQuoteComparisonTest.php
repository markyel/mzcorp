<?php

namespace Tests\Unit\Services\Quotes;

use App\Services\Quotes\AutoQuoteComparisonService as Cmp;
use App\Services\Quotes\AutoQuoteRuleService as Rule;
use Tests\TestCase;

/**
 * Виды расхождений между автоматическим КП и тем, что ушло клиенту.
 * Разбор кладём на чистые методы: сравнение строк и нормализацию артикулов.
 * Без БД и без сети.
 */
class AutoQuoteComparisonTest extends TestCase
{
    public function test_article_comparison_ignores_case_and_separators(): void
    {
        $this->assertSame(Rule::normalize('XO-508'), Rule::normalize('xo 508'));
        $this->assertSame(Rule::normalize('M00193'), Rule::normalize('m00193'));
        $this->assertNotSame(Rule::normalize('M00193'), Rule::normalize('M00194'));
        $this->assertSame('', Rule::normalize(null));
    }

    public function test_nothing_sent_is_told_apart_from_not_recognised(): void
    {
        // Кейс M-2026-16283: КП клиенту ушло вложением, парсер его не разобрал.
        // Подпись «клиенту ничего не ушло» была бы прямой неправдой.
        $this->assertNotSame(Cmp::LABELS[Cmp::KIND_NONE], Cmp::LABELS[Cmp::KIND_UNPARSED]);
        $this->assertStringContainsString('ничего не ушло', Cmp::LABELS[Cmp::KIND_NONE]);
        $this->assertStringContainsString('не распознан', Cmp::LABELS[Cmp::KIND_UNPARSED]);
    }

    public function test_labels_cover_every_kind(): void
    {
        // Подпись под чипом берётся по ключу — пропуск означает пустой чип.
        foreach ([Cmp::KIND_NONE, Cmp::KIND_UNPARSED, Cmp::KIND_SAME, Cmp::KIND_PRICE, Cmp::KIND_NOMENCLATURE, Cmp::KIND_COMPOSITION] as $kind) {
            $this->assertArrayHasKey($kind, Cmp::LABELS);
            $this->assertNotSame('', Cmp::LABELS[$kind]);
        }
    }

    public function test_price_tolerance_is_a_percent(): void
    {
        // Копеечные расхождения округления — не повод звать это «другая цена».
        $this->assertSame(0.01, Cmp::PRICE_TOLERANCE);
    }

    public function test_rule_thresholds_match_the_analysis(): void
    {
        // Порог 100 000 ₽ и только однострочные — из разбора 10 525 заявок.
        $this->assertSame(100_000.0, Rule::MAX_TOTAL);
        $this->assertSame(1, Rule::MAX_LINES);
    }
}
