<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Множественные фото на позицию заявки (many-to-many с email_attachments)
 * + признак «главное фото» (is_main).
 *
 * До этого позиция держала одно фото в request_items.image_attachment_id.
 * Этот столбец СОХРАНЯЕТСЯ как денормализованный указатель на главное фото —
 * чтобы существующие thumbnail-превью (RequestItem::imageAttachment) и метрики
 * продолжали работать без изменений. RequestItemEditor::syncPhotos держит
 * image_attachment_id в синхроне с pivot.is_main.
 *
 * Одно вложение может быть привязано к нескольким позициям (общий план),
 * поэтому это именно many-to-many, а не one-to-many.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('request_item_photos')) {
            Schema::create('request_item_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_item_id')
                    ->constrained('request_items')
                    ->cascadeOnDelete();
                $table->foreignId('email_attachment_id')
                    ->constrained('email_attachments')
                    ->cascadeOnDelete();
                $table->boolean('is_main')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['request_item_id', 'email_attachment_id'], 'request_item_photos_unique');
                $table->index('email_attachment_id', 'request_item_photos_attachment_idx');
            });
        }

        // Backfill: текущее одиночное image_attachment_id → pivot-строка
        // is_main=true. insertOrIgnore защищает от повторного прогона.
        $now = now();
        DB::table('request_items')
            ->whereNotNull('image_attachment_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now) {
                $insert = [];
                foreach ($rows as $r) {
                    $insert[] = [
                        'request_item_id' => $r->id,
                        'email_attachment_id' => $r->image_attachment_id,
                        'is_main' => true,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (! empty($insert)) {
                    DB::table('request_item_photos')->insertOrIgnore($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_item_photos');
    }
};
