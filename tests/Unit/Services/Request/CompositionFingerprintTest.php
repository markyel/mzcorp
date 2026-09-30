<?php

namespace Tests\Unit\Services\Request;

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
        $earlier = $this->request([['catalog_item_id' => 26011]]);
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

    public function test_a_name_only_matches_a_name_only(): void
    {
        $a = $this->request([['name' => 'Кнопка для Thyssen']]);
        $b = $this->request([['name' => 'кнопка для thyssen ']]);
        $c = $this->request([['name' => 'Кнопка для Thyssen', 'article' => 'MS41']]);

        $this->assertTrue($this->same($a, $b));
        $this->assertFalse($this->same($a, $c));
    }

    public function test_a_request_without_anything_identifiable_has_no_twin(): void
    {
        $this->assertNull(AssignmentService::compositionFingerprint($this->request([])));
        $this->assertNull(AssignmentService::compositionFingerprint($this->request([
            ['name' => '   '], ['article' => '—'],
        ])));
    }
}
