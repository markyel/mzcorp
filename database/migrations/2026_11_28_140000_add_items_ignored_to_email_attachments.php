<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Файл письма, в котором парсер не ищет позиции: менеджер отметил «не искать позиции» (MZ2026-02199, 20.11.2026: клиент
 * просит счёт по служебной записке, остальные приложенные документы — не заявка).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('email_attachments', 'items_ignored_at')) {
                $table->timestamp('items_ignored_at')->nullable();
            }
            if (! Schema::hasColumn('email_attachments', 'items_ignored_by_user_id')) {
                $table->foreignId('items_ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('email_attachments', 'items_ignored_by_user_id')) {
                $table->dropConstrainedForeignId('items_ignored_by_user_id');
            }
            if (Schema::hasColumn('email_attachments', 'items_ignored_at')) {
                $table->dropColumn('items_ignored_at');
            }
        });
    }
};
