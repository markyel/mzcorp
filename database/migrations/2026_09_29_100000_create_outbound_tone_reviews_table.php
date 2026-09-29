<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Проверка исходящих писем менеджеров клиентам на соответствие образу
 * компании (медиапрофиль): тон, перекладывание ответственности, гарантийная
 * политика, отказ без альтернативы. Кейс M-2026-17474: «сертификатов нет ни на
 * что… ответственность на вас… гарантии на КВШ нет».
 *
 * Одна строка — одно письмо; проверенное повторно не проверяется.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_tone_reviews')) {
            return;
        }

        Schema::create('outbound_tone_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->unique()->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->index();
            // ok — замечаний нет, issue — есть.
            $table->string('verdict', 16)->index();
            // 1 — стилистика, 2 — заметно портит образ, 3 — отталкивает / противоречит политике.
            $table->unsignedTinyInteger('severity')->default(0)->index();
            $table->jsonb('categories')->nullable();
            $table->text('quote')->nullable();
            $table->text('comment')->nullable();
            $table->text('suggestion')->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_tone_reviews');
    }
};
