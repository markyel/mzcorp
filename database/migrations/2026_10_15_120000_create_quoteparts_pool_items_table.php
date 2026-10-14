<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quoteparts_pool_items')) {
            return;
        }

        // Позиции каталога, работа по которым передана в QuoteParts. Статус и версия —
        // ответ QuoteParts на последнюю передачу; payload_hash — чтобы досылать только
        // реально изменившиеся карточки после очередной загрузки каталога.
        Schema::create('quoteparts_pool_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
            $table->string('sku', 32)->index();
            // pending — ещё не передана; active / paused / archived — как в QuoteParts; error — передача не удалась.
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('remote_version')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('pause_reasons')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quoteparts_pool_items');
    }
};
