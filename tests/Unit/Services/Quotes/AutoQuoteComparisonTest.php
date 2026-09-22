<?php

namespace Tests\Unit\Services\Quotes;

use App\Enums\OrganizationPricingMode;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Models\RequestItem;
use App\Services\Quotes\AutoQuoteComparisonService as Cmp;
use App\Services\Quotes\AutoQuoteRuleService as Rule;
use Illuminate\Support\Collection;
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

    public function test_delivery_line_is_a_service_not_a_difference(): void
    {
        // Кейс M-2026-14969: товар и цена сошлись, менеджер дописал доставку.
        // Это нормальная работа, а не расхождение состава.
        $this->assertTrue(Cmp::isServiceLine(['sku' => '', 'name' => "Доставка ЭКСПРЕСС по адресу '305003, г.Курск"]));
        $this->assertTrue(Cmp::isServiceLine(['sku' => '—', 'name' => 'Транспортные расходы']));
        $this->assertTrue(Cmp::isServiceLine(['sku' => null, 'name' => 'Упаковка деревянная']));

        // Товарная строка услугой не считается, даже если про доставку в имени.
        $this->assertFalse(Cmp::isServiceLine(['sku' => 'M16741', 'name' => 'Плата управления VEG2000']));
        $this->assertFalse(Cmp::isServiceLine(['sku' => 'M04667', 'name' => 'Блок доставки приводов']));
    }

    public function test_labels_cover_every_kind(): void
    {
        // Подпись под чипом берётся по ключу — пропуск означает пустой чип.
        foreach ([Cmp::KIND_NONE, Cmp::KIND_UNPARSED, Cmp::KIND_DELIVERY, Cmp::KIND_SAME, Cmp::KIND_PRICE, Cmp::KIND_NOMENCLATURE, Cmp::KIND_COMPOSITION] as $kind) {
            $this->assertArrayHasKey($kind, Cmp::LABELS);
            $this->assertNotSame('', Cmp::LABELS[$kind]);
        }
    }

    public function test_price_tolerance_is_a_percent(): void
    {
        // Копеечные расхождения округления — не повод звать это «другая цена».
        $this->assertSame(0.01, Cmp::PRICE_TOLERANCE);
    }

    public function test_one_email_two_companies_gets_the_better_terms(): void
    {
        // Кейс M-2026-16261: контакт заведён у ООО «ЗИПИС» (20%) и у
        // «ИНДУСТРИЯ СЕРВИСА» (15%), клиент не пишет, на кого выставлять.
        // Решение заказчика: даём лучшие условия.
        $zipis = new Organization(['name' => 'ООО «ЗИПИС»', 'discount_percent' => 20]);
        $other = new Organization(['name' => 'ИНДУСТРИЯ СЕРВИСА', 'discount_percent' => 15]);

        $picked = Rule::mostGenerous(
            new Collection([$other, $zipis]),
            fn (Organization $o) => (float) $o->discount_percent,
        );

        $this->assertSame('ООО «ЗИПИС»', $picked?->name);
    }

    public function test_cost_plus_wins_over_any_discount(): void
    {
        // «Себестоимость + наценка» — режим для особых клиентов, он почти
        // всегда ниже каталожной цены со скидкой.
        $special = new Organization(['name' => 'Спецклиент', 'pricing_mode' => OrganizationPricingMode::CostPlus]);
        $plain = new Organization(['name' => 'Обычный', 'discount_percent' => 20]);

        $picked = Rule::mostGenerous(
            new Collection([$plain, $special]),
            fn (Organization $o) => (float) $o->discount_percent,
        );

        $this->assertSame('Спецклиент', $picked?->name);
    }

    public function test_two_articles_in_one_line_are_spotted(): void
    {
        // Кейс M-2026-16171: клиент просил M00011 И M25915, парсер сложил оба
        // в одну позицию, и заявка выглядела однострочной. Автомат выдал бы
        // КП на половину запроса.
        $item = new RequestItem([
            'parsed_article' => 'FAA24350BL2, M00011, M25915',
            'parsed_name' => 'Редуктор с мотором AT120 ЛЕВЫЙ',
        ]);
        $item->setRelation('catalogItem', new CatalogItem(['sku' => 'M00011']));

        $this->assertSame(['M25915'], Rule::foreignSkusInLine($item));
    }

    public function test_a_clean_line_has_no_foreign_articles(): void
    {
        $item = new RequestItem(['parsed_article' => 'M00011', 'parsed_name' => 'Редуктор AT120']);
        $item->setRelation('catalogItem', new CatalogItem(['sku' => 'M00011']));

        $this->assertSame([], Rule::foreignSkusInLine($item));
    }

    public function test_rule_thresholds_match_the_analysis(): void
    {
        // Порог 100 000 ₽ и только однострочные — из разбора 10 525 заявок.
        $this->assertSame(100_000.0, Rule::MAX_TOTAL);
        $this->assertSame(1, Rule::MAX_LINES);
    }
}
