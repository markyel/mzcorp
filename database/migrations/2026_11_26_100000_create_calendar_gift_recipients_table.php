<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Получатели фирменного календаря (18.11.2026): подписчик канала Telegram / MAX записался через бота заявок —
 * подписку проверил бот канала, данные для доставки спросил бот. Одна запись на человека в кампании (год календаря);
 * лимита на организацию нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_gift_recipients')) {
            return;
        }
        Schema::create('calendar_gift_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 20)->index();
            $table->foreignId('messenger_chat_id')->nullable()->constrained('messenger_chats')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('external_user_id', 64);
            $table->string('contact_name', 255);
            $table->string('company_name', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address');
            $table->foreignId('organization_id')->nullable()->index()->constrained('organizations')->nullOnDelete();
            // new — записался, sent — отправлен, cancelled — отменён.
            $table->string('status', 20)->default('new')->index();
            $table->timestamp('consent_at')->nullable();
            // Подписка на канал: проверена при записи и при повторной проверке перед отправкой.
            $table->boolean('subscribed')->default(false);
            $table->timestamp('subscription_checked_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['campaign', 'channel', 'external_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_gift_recipients');
    }
};
