<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка позиции заявки к фото, из которого её извлёк Vision-парсер.
 *
 * Vision-промпт (`parseItemsFromPhotoMarkings`) теперь возвращает поле
 * `image_index` (0..N-1, по порядку входных фото), и persister резолвит
 * его в конкретный `email_attachments.id`. Используется в UI «Позиции»
 * для thumbnail-превью и для оценки «по какой позиции какая информация
 * предоставлена» (полнота заявки).
 *
 * Null — позиция извлечена из текста / документа / при ошибке Vision-
 * mapping (image_index out of range).
 *
 * onDelete=set null — удаление вложения письма не валит item, FK просто
 * обнуляется (изображение в storage уже не вернуть, но позицию сохраняем).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'image_attachment_id')) {
                $table->foreignId('image_attachment_id')
                    ->nullable()
                    ->after('data_source')
                    ->constrained('email_attachments')
                    ->nullOnDelete();
                $table->index('image_attachment_id', 'request_items_image_attachment_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'image_attachment_id')) {
                $table->dropIndex('request_items_image_attachment_idx');
                $table->dropConstrainedForeignId('image_attachment_id');
            }
        });
    }
};
