<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Перепродавец» — ручная бизнес-классификация клиента на уровне e-mail
 * (покупает на перепродажу, в отличие от прямого заказчика). НЕ путать с
 * «дилером» (dealer_emails, авто по потоку заявок, влияет на распределение).
 * Перепродавец — чисто ручной статус, на распределение НЕ влияет. Ставится/
 * снимается менеджером в любой заявке, виден во всех заявках этого e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reseller_emails')) {
            Schema::create('reseller_emails', function (Blueprint $table) {
                $table->id();
                // email — lowercased + trimmed (ResellerEmailService::normalize).
                $table->string('email', 191)->unique();
                $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_emails');
    }
};
