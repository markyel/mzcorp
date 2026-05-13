<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.9: подпись менеджера для исходящих писем.
 *
 * Вставляется в body при createReply/createCompose через EmailDraftService.
 * Редактируется через /profile.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'email_signature')) {
                $table->text('email_signature')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email_signature')) {
                $table->dropColumn('email_signature');
            }
        });
    }
};
