<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Фото пачки: имя файла (= M-артикул позиции), найденная позиция каталога, исходник и результат.
        if (! Schema::hasTable('photo_batch_items')) {
            Schema::create('photo_batch_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('photo_batch_id')->index()->constrained()->cascadeOnDelete();
                $table->string('original_name', 255);
                $table->string('sku', 32)->nullable()->index();
                // Имя файла ровно артикул (M12345.jpg) — фото позиции; с хвостом (M12345_2.jpg) — только в архив.
                $table->boolean('sku_exact')->default(false);
                $table->foreignId('catalog_item_id')->nullable()->index()->constrained()->nullOnDelete();
                $table->string('source_path', 500);
                $table->string('result_path', 500)->nullable();
                // pending → done | failed
                $table->string('status', 16)->default('pending')->index();
                $table->text('error')->nullable();
                $table->string('cm_image_id', 64)->nullable();
                $table->boolean('saved_to_catalog')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_batch_items');
    }
};
