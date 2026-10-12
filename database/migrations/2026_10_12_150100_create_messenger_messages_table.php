<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал переписки ботов заявок: входящие клиента и ответы бота/менеджера.
 * request_id — когда сообщение относится к заявке (этап 2: переписка в боте).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messenger_messages')) {
            return;
        }

        Schema::create('messenger_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('messenger_chat_id')->constrained('messenger_chats')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->index()->constrained('requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();  // менеджер-автор
            $table->string('direction', 8);                      // in | out
            $table->string('kind', 24)->default('text');         // text | callback | start | contact | bot | manager
            $table->text('text')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['messenger_chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_messages');
    }
};
