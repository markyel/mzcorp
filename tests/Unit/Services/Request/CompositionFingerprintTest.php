<?php

namespace Tests\Unit\Services\Request;

use App\Enums\TwinGround;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Request\AssignmentService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;

/**
 * Отпечаток состава: по нему в пропорциональном режиме одинаковые заявки от
 * разных покупателей уходят одному менеджеру. Ошибка в обе стороны дорога:
 * слишком строгий отпечаток правило не сработает вовсе, слишком вольный —
 * свалит одному человеку весь поток по ходовой детали.
 */
class CompositionFingerprintTest extends TestCase
{
    /**
     * @param  array<int, array{catalog_item_id?: ?int, article?: ?string, name?: ?string}>  $items
     */
    private function request(array $items): Request
    {
        $request = new Request;
        $request->setRelation('items', new EloquentCollection(array_map(function (array $row) {
            $item = new RequestItem;
            $item->catalog_item_id = $row['catalog_item_id'] ?? null;
            $item->parsed_article = $row['article'] ?? null;
            $item->parsed_name = $row['name'] ?? null;

            return $item;
        }, $items)));

        return $request;
    }

    public function test_the_same_set_in_another_order_is_the_same_request(): void
    {
        $a = $this->request([['catalog_item_id' => 10], ['catalog_item_id' => 20]]);
        $b = $this->request([['catalog_item_id' => 20], ['catalog_item_id' => 10]]);

        $this->assertSame(
            AssignmentService::compositionFingerprint($a),
            AssignmentService::compositionFingerprint($b),
        );
    }

    public function test_an_extra_position_makes_it_a_different_request(): void
    {
        $a = $this->request([['catalog_item_id' => 10], ['catalog_item_id' => 20]]);
        $b = $this->request([['catalog_item_id' => 10], ['catalog_item_id' => 20], ['catalog_item_id' => 30]]);

        $this->assertNotSame(
            AssignmentService::compositionFingerprint($a),
            AssignmentService::compositionFingerprint($b),
        );
    }

    public function test_the_article_is_read_through_its_spelling(): void
    {
        // Регистр, пробелы и дефисы к делу не относятся — это один и тот же код.
        $a = $this->request([['article' => 'XO-508']]);
        $b = $this->request([['article' => ' xo 508 ']]);

        $this->assertSame(
            AssignmentService::compositionFingerprint($a),
            AssignmentService::compositionFingerprint($b),
        );
    }

    public function test_a_resolved_catalogue_item_is_not_the_same_as_a_bare_article(): void
    {
        // Разные ключи специально: пока позиция не сматчена с каталогом, мы не
        // знаем, тот ли это товар, и склеивать заявки по догадке нельзя.
        $a = $this->request([['catalog_item_id' => 10]]);
        $b = $this->request([['article' => 'M00010']]);

        $this->assertNotSame(
            AssignmentService::compositionFingerprint($a),
            AssignmentService::compositionFingerprint($b),
        );
    }

    private function same(Request $a, Request $b, array $catalogCodes = []): bool
    {
        return AssignmentService::sameComposition(
            AssignmentService::compositionItems($a, $catalogCodes),
            AssignmentService::compositionItems($b, $catalogCodes),
        );
    }

    public function test_a_fresh_request_matches_an_earlier_one_already_bound_to_the_catalogue(): void
    {
        // M-2026-17067 / M-2026-17075: у ранней каталог привязан и M-код
        // дописан в артикул, у новой в момент распределения — только артикул.
        $earlier = $this->request([['catalog_item_id' => 27132, 'article' => 'TAC20602A203, M07308']]);
        $fresh = $this->request([['article' => 'TAC20602A203']]);

        $this->assertTrue($this->same($fresh, $earlier));
    }

    public function test_the_catalogue_articles_count_for_the_bound_position(): void
    {
        $earlier = $this->request([['catalog_item_id' => 26011, 'name' => 'Частотный преобразователь Unidrive SP 2403']]);
        $fresh = $this->request([['article' => 'Unidrive SP 2403']]);

        $this->assertTrue($this->same($fresh, $earlier, [26011 => ['M08303', 'UNIDRIVESP2403']]));
        $this->assertFalse($this->same($fresh, $earlier));
    }

    public function test_different_catalogue_items_sharing_a_model_code_are_different(): void
    {
        $a = $this->request([['catalog_item_id' => 1, 'article' => 'AT120']]);
        $b = $this->request([['catalog_item_id' => 2, 'article' => 'AT120']]);

        $this->assertFalse($this->same($a, $b));
    }

    public function test_an_extra_position_breaks_the_match_either_way(): void
    {
        $a = $this->request([['article' => 'XO-508']]);
        $b = $this->request([['article' => 'XO-508'], ['article' => 'ZAA717AP1']]);

        $this->assertFalse($this->same($a, $b));
        $this->assertFalse($this->same($b, $a));
    }

    public function test_a_short_article_tail_does_not_join_different_parts(): void
    {
        $a = $this->request([['article' => 'SC-E02P/G']]);
        $b = $this->request([['article' => 'SC-N2S/G']]);

        $this->assertFalse($this->same($a, $b));
    }

    public function test_a_name_only_matches_a_name_only(): void
    {
        $a = $this->request([['name' => 'Кнопка для Thyssen']]);
        $b = $this->request([['name' => 'кнопка для thyssen ']]);
        $c = $this->request([['name' => 'Кнопка для Thyssen', 'article' => 'MS41']]);

        $this->assertTrue($this->same($a, $b));
        $this->assertFalse($this->same($a, $c));
    }

    public function test_a_bare_name_matches_a_position_already_bound_to_the_catalogue(): void
    {
        // M-2026-21557 / M-2026-21581: у ранней — артикулы и каталог, у новой в момент
        // распределения только название (каталог привязался через 5 секунд).
        $name = 'Редуктор SRT 85 100 AC32 IEC80-B14 G619 без фланца, вал 32мм';
        $earlier = $this->request([['catalog_item_id' => 20346, 'article' => 'LW-0013324, M08827', 'name' => $name]]);
        $fresh = $this->request([['name' => $name]]);

        $this->assertTrue($this->same($fresh, $earlier, [20346 => ['M08827', 'SRT08500G632']]));
        $this->assertTrue($this->same($earlier, $fresh, [20346 => ['M08827', 'SRT08500G632']]));
    }

    public function test_a_same_name_does_not_join_positions_with_different_articles(): void
    {
        $name = 'Редуктор SRT 85 100 AC32';
        $a = $this->request([['article' => 'LW-0013324', 'name' => $name]]);
        $b = $this->request([['article' => 'LW-0099999', 'name' => $name]]);
        // Каталог без своих артикулов — тоже не «голое название».
        $c = $this->request([['catalog_item_id' => 7, 'name' => $name]]);

        $this->assertFalse($this->same($a, $b));
        $this->assertFalse($this->same($a, $c));
    }

    private function ground(Request $a, Request $b, array $catalogCodes = []): ?TwinGround
    {
        return AssignmentService::compositionGround(
            AssignmentService::compositionItems($a, $catalogCodes),
            AssignmentService::compositionItems($b, $catalogCodes),
        );
    }

    public function test_a_vague_position_is_not_glued_through_the_catalogue(): void
    {
        // «Кнопка с красной подсветкой» привязана к товару каталога догадкой — его артикул
        // не делает её той же кнопкой, что «тип AN301» с кодом.
        $vague = $this->request([['catalog_item_id' => 5657, 'name' => 'Кнопка с красной подсветкой']]);
        $exact = $this->request([['article' => 'M05657', 'name' => 'Кнопка приказа шестиугольная, тип AN301']]);
        $alsoBound = $this->request([['catalog_item_id' => 5657, 'article' => 'M05657', 'name' => 'Кнопка приказа шестиугольная, тип AN301']]);

        $this->assertNull($this->ground($exact, $vague, [5657 => ['M05657']]));
        $this->assertNull($this->ground($alsoBound, $vague, [5657 => ['M05657']]), 'one catalogue item is no ground for a vague side');
        // Две одинаково размытые — по-прежнему по названию.
        $this->assertSame(TwinGround::Name, $this->ground($vague, $this->request([['name' => 'кнопка с красной подсветкой']])));
    }

    public function test_left_and_right_are_different_parts_even_on_one_catalogue_item(): void
    {
        $left = $this->request([['catalog_item_id' => 9, 'article' => 'CAT.FEIS', 'name' => 'Доводчик дверей Fermator, левое открывание, 700 мм']]);
        $right = $this->request([['catalog_item_id' => 9, 'article' => 'CAT.FEIS', 'name' => 'Доводчик дверей правый']]);

        $this->assertNull($this->ground($left, $right));
    }

    public function test_numbers_with_the_same_unit_must_share_a_value(): void
    {
        $ten = $this->request([['catalog_item_id' => 4626, 'article' => 'XBF30', 'name' => 'Ремень тяговый Otis 30 мм, толщина 3.3 мм, Gen2, 10 кордов']]);
        $twelve = $this->request([['catalog_item_id' => 4626, 'article' => 'XBF30', 'name' => 'Ремень тяговый Otis 30 мм (толщина 3,3 мм) Gen2 12 кордов']]);
        $this->assertNull($this->ground($ten, $twelve));

        $one = $this->request([['article' => 'KT40', 'name' => 'Кнопка приказа Thyssen KT40, символ "1"']]);
        $four = $this->request([['article' => 'KT40', 'name' => 'Кнопка приказа Thyssen KT40, символ "4"']]);
        $this->assertNull($this->ground($one, $four));

        // Лишняя характеристика с одной стороны и «1,0» = «1» — не противоречие.
        $short = $this->request([['article' => 'GR63X25', 'name' => 'Электродвигатель 24VDC привода дверей Sematic GR63X25']]);
        $long = $this->request([['article' => 'GR63X25', 'name' => 'Эл.двигатель 24 VDC Sematic GR63X25, кабель 1500мм']]);
        $this->assertSame(TwinGround::OwnArticle, $this->ground($short, $long));
        $this->assertSame(TwinGround::OwnArticle, $this->ground(
            $this->request([['article' => 'GBP201', 'name' => 'Ограничитель скорости 1,0 м/с GBP201']]),
            $this->request([['article' => 'GBP201', 'name' => 'Ограничитель скорости GBP201 1 м/с']]),
        ));
    }

    public function test_the_ground_is_the_weakest_of_the_positions(): void
    {
        $earlier = $this->request([
            ['article' => 'TAC20602A203', 'name' => 'Ограничитель OTIS TAC20602A203'],
            ['catalog_item_id' => 26011, 'name' => 'Частотный преобразователь Unidrive SP 2403'],
        ]);
        $fresh = $this->request([
            ['article' => 'TAC20602A203', 'name' => 'Ограничитель TAC20602A203'],
            ['article' => 'Unidrive SP 2403', 'name' => 'Преобразователь'],
        ]);

        $this->assertSame(TwinGround::CatalogCode, $this->ground($fresh, $earlier, [26011 => ['M08303', 'UNIDRIVESP2403']]));
        $this->assertSame(TwinGround::OwnArticle, $this->ground(
            $this->request([['article' => 'TAC20602A203']]), $this->request([['article' => 'TAC20602A203, M07308']]),
        ));
    }

    public function test_experimental_mode_matches_similar_names_one_to_one(): void
    {
        $names = fn (array $items) => AssignmentService::positionNames($this->request($items));

        // Одна позиция, названия похожи, хотя артикулов нет и формулировки разные.
        $this->assertNotNull(AssignmentService::similarComposition(
            $names([['name' => 'Редуктор SRT 85 100 AC32 IEC80-B14 G619 без фланца, вал 32мм']]),
            $names([['name' => 'Редуктор SRT 85 100 AC32 IEC80-B14 G619, вал 32 мм']]),
            70,
        ));
        // Позиций не поровну — не близнецы, даже если одна совпала.
        $this->assertNull(AssignmentService::similarComposition(
            $names([['name' => 'Ролик двери Fermator']]),
            $names([['name' => 'Ролик двери Fermator'], ['name' => 'Башмак двери Fermator']]),
            70,
        ));
        // Каждой позиции — своя пара: две позиции одной заявки не делят одну позицию другой.
        $this->assertNull(AssignmentService::similarComposition(
            $names([['name' => 'Ролик двери Fermator 50 мм'], ['name' => 'Ролик двери Fermator 52 мм']]),
            $names([['name' => 'Ролик двери Fermator 50 мм'], ['name' => 'Частотный преобразователь Unidrive SP']]),
            70,
        ));
        // Порог: 100 — только одинаковые названия (регистр и пробелы не в счёт); без названия — артикул.
        $this->assertSame(100, AssignmentService::similarComposition($names([['name' => 'Кнопка  KT40']]), $names([['name' => 'кнопка kt40']]), 100));
        $this->assertNull(AssignmentService::similarComposition($names([['name' => 'Кнопка KT40']]), $names([['name' => 'Кнопка KT41']]), 100));
        $this->assertSame(['xo-508'], $names([['article' => 'XO-508']]));
    }

    public function test_a_request_without_anything_identifiable_has_no_twin(): void
    {
        $this->assertNull(AssignmentService::compositionFingerprint($this->request([])));
        $this->assertNull(AssignmentService::compositionFingerprint($this->request([
            ['name' => '   '], ['article' => '—'],
        ])));
    }
}
