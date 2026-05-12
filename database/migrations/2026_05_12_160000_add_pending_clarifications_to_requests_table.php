<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Очередь предположений LLM по уточнениям позиций (Phase 2).
 *
 * Когда клиент/поставщик в reply'е присылает не «новые позиции», а
 * УТОЧНЕНИЕ артикулов уже существующих (например, Liftway-auto-flow
 * сначала шлёт LW-* коды, потом оператор уточняет «это M21595, выставите
 * счёт»), парсер не должен слепо плодить дубли. Вместо этого
 * RequestItemParsingService::decideClarifications вызывает второй
 * (дешёвый mini-) LLM-проход, который решает: «это новая позиция» vs
 * «это уточнение к позиции N в request».
 *
 * Решения LLM записываются сюда как очередь, а не применяются
 * автоматически — оператор в карточке нажимает Apply / Reject. После
 * Apply артикул дописывается в `request_items.parsed_article` через
 * `, `, запись из очереди удаляется. После Reject — просто удаление
 * из очереди.
 *
 * Структура каждого элемента массива:
 *   {
 *     "id":                     "clr_<uuid>",
 *     "source_email_message_id": 12345,
 *     "target_position":        1,
 *     "additional_article":     "M21595",
 *     "additional_brand":       null,
 *     "reasoning":              "LLM объяснение почему это уточнение",
 *     "created_at":             "2026-05-12T13:51:00Z"
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'pending_clarifications')) {
                $table->jsonb('pending_clarifications')->nullable()->after('subject');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'pending_clarifications')) {
                $table->dropColumn('pending_clarifications');
            }
        });
    }
};
