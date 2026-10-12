<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клиенты ботов заявок в Telegram и MAX (BotConversationService): кто пишет,
 * подтверждённый e-mail, контакты, организация и шаг диалога (state).
 * Один пользователь мессенджера — одна строка на канал.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messenger_chats')) {
            return;
        }

        Schema::create('messenger_chats', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16);                       // telegram | max
            $table->string('external_user_id', 64);              // id пользователя в мессенджере
            $table->string('chat_id', 64)->index();              // куда отвечать
            $table->string('username', 120)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('email', 255)->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('contact_name', 200)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('company_name', 300)->nullable();
            $table->string('inn', 20)->nullable();
            $table->foreignId('organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            $table->jsonb('state')->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('requests_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_chats');
    }
};
