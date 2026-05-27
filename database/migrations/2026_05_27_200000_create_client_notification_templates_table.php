<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблоны автоматических уведомлений клиенту.
 *
 * 1 row на каждый ClientNotificationType. Seeder создаёт 5 строк с
 * дефолтными русскими текстами (toggle is_enabled=false для безопасности
 * первого запуска — admin включает каждый тип явно).
 *
 * Тексты хранятся как Markdown с `{{ placeholder }}` подстановками.
 * `ClientNotificationService::render` парсит Markdown → HTML и подставляет
 * placeholder'ы перед отправкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_notification_templates')) {
            return;
        }

        Schema::create('client_notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64)->unique(); // ClientNotificationType value
            $table->boolean('is_enabled')->default(false);
            $table->string('subject_template', 500);
            $table->text('body_template');
            // Если задано — переопределяет AttentionService threshold по умолчанию (в часах).
            $table->integer('threshold_hours')->nullable();
            // Для invoice_expiring_soon — за сколько дней слать.
            $table->integer('warning_days')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('client_notification_templates')) {
            Schema::drop('client_notification_templates');
        }
    }
};
