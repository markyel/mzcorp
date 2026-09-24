<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закреплённая организация контакта.
 *
 * Реестр клиентов наполняется сам: разбор реквизитов из наших же PDF
 * связывает адрес заявки с покупателем из документа. Для посредника это
 * ломается — с адреса Liftway за год ушло 1928 документов на ИП Маркелова и
 * одиннадцать на конечных заказчиков, и адрес стал «многоюрлицным»: система
 * начала выбирать, чьи условия применить.
 *
 * Закрепление говорит: у этого адреса заказчик один, что бы ни было написано
 * в отдельных документах. Автоматика после этого не трогает его связи, а
 * подбор организации для КП и авто-КП всегда даёт закреплённую.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_contacts') && ! Schema::hasColumn('client_contacts', 'pinned_organization_id')) {
            Schema::table('client_contacts', function (Blueprint $table) {
                $table->foreignId('pinned_organization_id')->nullable()
                    ->constrained('organizations')->nullOnDelete();
                $table->timestamp('pinned_at')->nullable();
                $table->foreignId('pinned_by_user_id')->nullable()
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_contacts') && Schema::hasColumn('client_contacts', 'pinned_organization_id')) {
            Schema::table('client_contacts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('pinned_organization_id');
                $table->dropConstrainedForeignId('pinned_by_user_id');
                $table->dropColumn('pinned_at');
            });
        }
    }
};
