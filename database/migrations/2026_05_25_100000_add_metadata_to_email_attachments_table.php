<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo Classifier (2026-05-21): добавляем jsonb-колонку email_attachments.metadata
 * для хранения результатов Vision-классификации фоток по KB photo-slot'ам.
 *
 * Структура metadata (примеры ключей, заполняется PhotoSlotClassifierService):
 * {
 *   "kb_slot_candidates": [
 *     {
 *       "request_item_id": 2114,           // к какой позиции относится этот матч
 *       "slug": "photo_nameplate",          // KB photo-slug
 *       "confidence": 0.92,                  // уверенность Vision
 *       "description": "виден шильдик Schindler с артикулом 5550287",
 *       "classified_at": "2026-05-21T..."
 *     },
 *     ...
 *   ],
 *   "vision_classified_at": "2026-05-21T..."  // когда классификатор последний раз бегал
 * }
 *
 * Колонка добавляется nullable — старые записи останутся без метаданных,
 * классификатор лениво заполнит при первом запуске. Откат сносит колонку
 * вместе с данными (это деривативные данные, можно перепрогнать).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_attachments')) {
            return;
        }
        if (Schema::hasColumn('email_attachments', 'metadata')) {
            return;
        }
        Schema::table('email_attachments', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable()->after('is_inline');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_attachments')) {
            return;
        }
        if (! Schema::hasColumn('email_attachments', 'metadata')) {
            return;
        }
        Schema::table('email_attachments', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
