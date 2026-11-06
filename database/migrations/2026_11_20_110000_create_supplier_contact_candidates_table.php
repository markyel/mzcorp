<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Претенденты в контакты поставщиков (переезд поставщиков, решение заказчика 06.11.2026): новый
 * адрес из переписки с поставщиком больше не заводится в реестр сам — человек подтверждает, чей
 * это контакт (существующего поставщика, нового) или что это не поставщик.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_contact_candidates')) {
            return;
        }

        Schema::create('supplier_contact_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('name', 255)->nullable();
            // outbound_rfq — наш запрос цены; marked_thread — тред помечен поставщиком; close_lost — заявка закрыта как переписка с поставщиком.
            $table->string('source', 32);
            $table->string('context', 255)->nullable();
            $table->foreignId('suggested_organization_id')->nullable()->constrained('supplier_organizations')->nullOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_contact_candidates');
    }
};
