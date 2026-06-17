<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь M:N организация ↔ контакт (email). Один email может относиться к
 * нескольким организациям; одна организация может слать запросы с разных email.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_contact')) {
            return;
        }
        Schema::create('organization_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_contact_id')->constrained('client_contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'client_contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_contact');
    }
};
