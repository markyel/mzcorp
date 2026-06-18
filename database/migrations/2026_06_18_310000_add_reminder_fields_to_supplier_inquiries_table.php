<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Авто-напоминания поставщикам (Фаза 3.5): счётчик отправленных напоминаний и
 * момент последнего — для интервала/лимита в SupplierReminderService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_inquiries', 'reminders_sent')) {
                $table->unsignedSmallInteger('reminders_sent')->default(0);
            }
            if (! Schema::hasColumn('supplier_inquiries', 'last_reminder_at')) {
                $table->timestamp('last_reminder_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            foreach (['reminders_sent', 'last_reminder_at'] as $col) {
                if (Schema::hasColumn('supplier_inquiries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
