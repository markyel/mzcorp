<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Уникальный токен RFQ на инквайри поставщика. Ставится маркером `[RFQ-<token>]`
 * в тему письма-запроса; поставщик сохраняет тему при ответе → детерминированно
 * матчим ответ к КОНКРЕТНОМУ (заявка/пул × поставщик), игнорируя всю прочую
 * переписку снабжения с поставщиком (закупки/отгрузки/рекламации). Особенно
 * важно для позиция-центричных RFQ снабжения (related_request_id=null), где
 * раньше тема была просто «Запрос расценки» без метки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_inquiries', 'rfq_token')) {
                $table->string('rfq_token', 16)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_inquiries', 'rfq_token')) {
                $table->dropColumn('rfq_token');
            }
        });
    }
};
