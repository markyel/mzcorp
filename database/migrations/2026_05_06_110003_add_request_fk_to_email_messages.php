<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет FK email_messages.related_request_id → requests.id.
 *
 * Колонка была заложена ещё в Phase 1.3 без FK (т.к. таблицы requests
 * тогда не существовало). Сейчас добавляем FK с nullOnDelete.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreign('related_request_id')
                ->references('id')->on('requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropForeign(['related_request_id']);
        });
    }
};
