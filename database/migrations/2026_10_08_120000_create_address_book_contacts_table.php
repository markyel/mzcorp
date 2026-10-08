<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личная адресная книга почты: контакты, которые пользователь сам добавил
 * (у каждого своя). Коллеги, клиенты из реестра, поставщики и недавние
 * адресаты в неё не копируются — подтягиваются в поиске на лету
 * (App\Services\Mail\AddressBookService).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('address_book_contacts')) {
            return;
        }

        Schema::create('address_book_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Адрес храним в нижнем регистре — по нему уникальность в книге.
            $table->string('email', 320);
            $table->string('name')->nullable();
            $table->string('organization')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_book_contacts');
    }
};
