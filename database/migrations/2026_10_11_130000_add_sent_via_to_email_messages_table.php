<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каким путём ушло исходящее, если не основным SMTP ящика: имя резервного
 * канала (services.mail_outbound.fallback.name, напр. «Unisender Go»).
 * NULL — обычная отправка через SMTP Яндекса. См. OutgoingMailSender.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_messages', 'sent_via')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->string('sent_via', 60)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_messages', 'sent_via')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->dropColumn('sent_via');
            });
        }
    }
};
