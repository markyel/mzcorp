<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaTopic;
use App\Services\Marketing\MediaDataService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Фото к ассортиментному посту — по артикулам из готового текста (12.10.2026:
 * пост о пяти новинках ушёл с фото четырёх других кнопок). БД — SQLite в
 * памяти с минимальной таблицей каталога.
 */
class MediaPhotosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.photos_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'photos_test',
        ]);
        Schema::create('catalog_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->string('photo_url')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $now = now();
        DB::table('catalog_items')->insert([
            ['sku' => 'M34594', 'photo_url' => null, 'is_active' => true, 'created_at' => $now],
            ['sku' => 'M34596', 'photo_url' => 'https://img/plata', 'is_active' => true, 'created_at' => $now],
            ['sku' => 'M34595', 'photo_url' => 'https://img/btn-2', 'is_active' => true, 'created_at' => $now],
            ['sku' => 'M34593', 'photo_url' => 'https://img/btn-3', 'is_active' => true, 'created_at' => $now],
            ['sku' => 'M34584', 'photo_url' => 'https://img/igbt', 'is_active' => true, 'created_at' => $now->copy()->subDay()],
        ]);
    }

    private function topic(string $source): MediaTopic
    {
        return new MediaTopic(['title' => 'Новые позиции', 'source' => $source, 'cadence_days' => 7]);
    }

    public function test_photos_follow_skus_of_the_written_text_in_order(): void
    {
        $body = "• Выключатель · арт. M34594\n• Плата · арт. M34596\n• Кнопка «-2» · арт. M34595\n• IGBT модуль · арт. M34584";

        $photos = app(MediaDataService::class)->photosFor($this->topic('catalog_new'), null, $body);

        // M34594 без фото — пропуск, а не чужая картинка; M34593 в тексте нет.
        $this->assertSame(['https://img/plata', 'https://img/btn-2', 'https://img/igbt'], $photos);
    }

    public function test_text_without_skus_keeps_the_old_selection(): void
    {
        $photos = app(MediaDataService::class)->photosFor($this->topic('catalog_new'), null, 'Обзор без артикулов');

        $this->assertContains('https://img/btn-3', $photos);
    }
}
