<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стоп-лист авто-уведомлений по e-mail клиента.
 *
 * Для адреса из этого списка `ClientNotificationService::dispatch` НЕ
 * отправляет авто-уведомления, типы которых перечислены в `suppressed_types`
 * (значения App\Enums\ClientNotificationType). Типы, которых в списке нет,
 * отправляются как обычно — поэтому добавление НОВОГО типа уведомления не
 * глушится задним числом для существующих записей.
 *
 * Матч — по точному lowercase-email (`email`), без plus-addressing-магии:
 * адрес клиента в Request.client_email уже нормализован источником.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_notification_optouts')) {
            return;
        }

        Schema::create('client_notification_optouts', function (Blueprint $table) {
            $table->id();

            $table->string('email')->unique()
                ->comment('lowercase e-mail клиента, по которому глушим уведомления');

            $table->jsonb('suppressed_types')->nullable()
                ->comment('Явный список ClientNotificationType-значений, которые НЕ слать (UI = «всё минус оставленные»). Тип вне списка шлётся как обычно — новый тип не глушится задним числом.');

            $table->text('comment')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notification_optouts');
    }
};
