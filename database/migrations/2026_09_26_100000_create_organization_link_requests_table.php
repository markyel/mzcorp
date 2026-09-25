<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сомнительные привязки контрагента к адресу заказчика — на подтверждение.
 *
 * Реквизиты уже известного контрагента (у него есть свои адреса) оказались
 * в документе, ушедшем на чужой адрес: так ООО «ЛИФТРЕМОНТ» повис на адресе
 * КОМБОЛИФТ СЕРВИС. Автоматика такую связь не создаёт — заводит запись здесь
 * и пишет менеджеру, выдавшему документ. Связь появляется, только когда
 * человек подтвердит.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_link_requests')) {
            return;
        }

        Schema::create('organization_link_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('client_contact_id')->constrained('client_contacts')->cascadeOnDelete();
            // Откуда попытка: outbound_quote (КП/счёт из почты), quotation (наше КП), web_form.
            $table->string('source', 32);
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignId('outbound_quote_id')->nullable()->constrained('outbound_quotes')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->string('document_type', 32)->nullable();
            $table->string('document_number', 64)->nullable();
            // Адреса, к которым контрагент уже был привязан на момент попытки.
            $table->jsonb('known_emails')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('notified_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // Одна пара — одно решение: повторные документы не плодят писем.
            $table->unique(['organization_id', 'client_contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_link_requests');
    }
};
