<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Скрытая копия исходящего письма: [{email, name}], как to/cc_recipients.
 * В само письмо заголовок Bcc не попадает (Symfony Mime убирает его при
 * отправке) — храним, чтобы автор видел, кому ушла скрытая копия.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_messages', 'bcc_recipients')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->jsonb('bcc_recipients')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_messages', 'bcc_recipients')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->dropColumn('bcc_recipients');
            });
        }
    }
};
