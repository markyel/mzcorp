<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контактные лица клиентов (раздел «Клиенты») — единица хранения по e-mail
 * заказчика. ФИО + телефон. Связь с организациями — M:N через
 * organization_contact (один email может относиться к нескольким организациям).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_contacts')) {
            return;
        }
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('full_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
