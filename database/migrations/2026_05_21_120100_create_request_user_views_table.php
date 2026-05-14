<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot «менеджер открыл карточку заявки в последний раз».
 *
 * Нужна для clear-логики attention_reason=ClientReplied: когда клиент
 * прислал inbound, AttentionService::onClientReplied ставит attention.
 * Detail::mount фиксирует last_seen_at; AttentionService::onManagerOpened
 * (вызывается оттуда же) делает recompute — ClientReplied сменяется на
 * SlaBreach по статусу или null.
 *
 * Pivot, а не колонка `requests.last_seen_at`, потому что:
 *  - открытие разными менеджерами учитывается отдельно (assigned + acting
 *    delegate + privileged head_of_sales/director видят одну заявку);
 *  - в Pool можно показать каждому свой «непрочитанный» индикатор.
 *
 * Unique (request_id, user_id) — upsert при каждом mount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_user_views')) {
            return;
        }

        Schema::create('request_user_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['request_id', 'user_id']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_user_views');
    }
};
