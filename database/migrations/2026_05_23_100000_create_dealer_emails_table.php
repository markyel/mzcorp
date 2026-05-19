<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Авто-пометка «дилерских» email-адресов. Если у одного `client_email`
 * накопилось N+ открытых заявок — для распределения сигнал
 * pickStickyByClientEmail (1b в AssignmentService) ОТКЛЮЧАЕТСЯ:
 * дилерские потоки больше не липнут к одному менеджеру, идут
 * через round-robin.
 *
 * Полный автомат, без ручного управления (только порог в Настройках).
 * Catalog/text-sticky продолжают работать для дилеров.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dealer_emails')) {
            Schema::create('dealer_emails', function (Blueprint $table) {
                $table->id();
                // email хранится lowercased + trimmed (DealerEmailService::normalize).
                $table->string('email', 191)->unique();
                // snapshot для аудита: сколько открытых заявок было в момент пометки.
                $table->unsignedInteger('open_count_at_mark');
                $table->timestamp('marked_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_emails');
    }
};
