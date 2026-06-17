<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр организаций-клиентов (раздел «Клиенты»). Хранит юр.идентичность
 * (Название / ИНН / КПП), реквизиты для подстановки в КП/счёт и размер скидки.
 * С контактами (email) связана M:N через organization_contact: у одного email
 * может быть несколько организаций, и наоборот.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organizations')) {
            return;
        }
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('inn', 20)->nullable();
            $table->string('kpp', 20)->nullable();
            $table->string('address')->nullable();
            // Свободный блок реквизитов для КП/счёта (банк, р/с, к/с, БИК, ОГРН).
            $table->text('requisites_text')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('inn');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
