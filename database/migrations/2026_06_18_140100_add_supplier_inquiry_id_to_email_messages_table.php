<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка письма к запросу поставщику (переписка с поставщиком).
 *
 * Письма, прицепленные к SupplierInquiry, — это наша переписка с поставщиком
 * (ответы на запрос расценки). Они НЕ создают клиентскую заявку (гард в
 * MailRouter) и категоризируются как EmailCategory::SupplierReply.
 * nullOnDelete: удаление запроса поставщику обнуляет ссылку, письма остаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('email_messages', 'supplier_inquiry_id')) {
            return;
        }
        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreignId('supplier_inquiry_id')->nullable()
                ->constrained('supplier_inquiries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('email_messages', 'supplier_inquiry_id')) {
            return;
        }
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_inquiry_id');
        });
    }
};
