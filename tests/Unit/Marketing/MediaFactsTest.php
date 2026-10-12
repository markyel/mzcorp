<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaTopic;
use App\Services\Marketing\MediaDataService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Факты ассортиментных тем без повторов (12.10.2026): поступления — только
 * появившиеся в наличии позиции, снижение цен — одна строка на позицию и
 * только если цена и сейчас ниже, период — от прошлого выпуска.
 */
class MediaFactsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 09:15:00');
        config([
            'database.connections.facts_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'facts_test',
        ]);
        Schema::create('catalog_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('part_type')->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->decimal('stock_available', 12, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('in_stock_since')->nullable();
            $t->timestamp('last_imported_at')->nullable();
            $t->timestamps();
        });
        Schema::create('catalog_price_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('catalog_item_id');
            $t->string('sku');
            $t->decimal('old_price', 12, 2)->nullable();
            $t->decimal('new_price', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('media_publications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('media_topic_id')->nullable();
            $t->string('status')->default('draft');
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $sku, array $attrs = []): int
    {
        return DB::table('catalog_items')->insertGetId(array_merge([
            'sku' => $sku, 'name' => 'Деталь', 'price' => 1000, 'stock_available' => 0,
            'is_active' => true, 'created_at' => '2026-06-01 00:00:00', 'last_imported_at' => '2026-10-12 06:00:00',
        ], $attrs));
    }

    private function change(int $id, string $sku, float $old, float $new, string $at): void
    {
        DB::table('catalog_price_changes')->insert(['catalog_item_id' => $id, 'sku' => $sku, 'old_price' => $old, 'new_price' => $new, 'created_at' => $at]);
    }

    private function facts(string $source, int $topicId = 1): string
    {
        $topic = new MediaTopic(['source' => $source, 'cadence_days' => 7]);
        $topic->id = $topicId;

        return app(MediaDataService::class)->factsWithKey($topic)['facts'];
    }

    public function test_arrivals_are_items_that_came_back_in_stock_not_biggest_stock(): void
    {
        $this->item('M25447', ['stock_available' => 500]);                                          // давно в наличии
        $this->item('M10001', ['stock_available' => 3, 'in_stock_since' => '2026-10-10 06:00:00']);  // поступила
        $this->item('M10002', ['stock_available' => 2, 'in_stock_since' => '2026-10-11 06:00:00', 'created_at' => '2026-10-11 06:00:00']); // новинка

        $facts = $this->facts('stock_arrivals');

        $this->assertStringContainsString('M10001', $facts);
        $this->assertStringNotContainsString('M25447', $facts);
        $this->assertStringNotContainsString('M10002', $facts);
    }

    public function test_no_arrivals_means_no_facts(): void
    {
        $this->item('M25447', ['stock_available' => 500]);

        $this->assertSame('', $this->facts('stock_arrivals'));
    }

    public function test_price_drop_is_one_line_per_item_and_only_if_still_lower(): void
    {
        $a = $this->item('M20001', ['price' => 800]);
        $this->change($a, 'M20001', 1000, 900, '2026-10-07 06:00:00');
        $this->change($a, 'M20001', 900, 800, '2026-10-09 06:00:00');
        $b = $this->item('M20002', ['price' => 1000]);                  // снизили и вернули
        $this->change($b, 'M20002', 1000, 900, '2026-10-07 06:00:00');
        $this->change($b, 'M20002', 900, 1000, '2026-10-09 06:00:00');

        $facts = $this->facts('catalog_price');

        $this->assertSame(1, substr_count($facts, 'M20001'));
        $this->assertStringContainsString('было 1 000 ₽, стало 800 ₽ (−20%)', $facts);
        $this->assertStringNotContainsString('M20002', $facts);
    }

    public function test_period_starts_at_previous_release_when_it_was_late(): void
    {
        // Прошлый выпуск писался 08.10 (опоздал), окно «7 дней» началось бы 05.10.
        DB::table('media_publications')->insert(['media_topic_id' => 1, 'status' => 'published', 'created_at' => '2026-10-08 09:15:00']);
        $this->item('M30001', ['created_at' => '2026-10-06 10:00:00']);   // уже было в прошлом выпуске
        $this->item('M30002', ['created_at' => '2026-10-09 10:00:00']);

        $facts = $this->facts('catalog_new');

        $this->assertStringContainsString('M30002', $facts);
        $this->assertStringNotContainsString('M30001', $facts);
        $this->assertStringContainsString('с 08.10', $facts);
    }
}
