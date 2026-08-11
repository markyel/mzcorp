<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручное управление статусом «перепродавец» (дилер) на уровне e-mail.
 * Раньше dealer_emails был чисто авто (порог по потоку). Теперь менеджер может
 * пометить/снять статус в любой заявке; статус виден во всех заявках этого
 * отправителя.
 *   - marked_by_user_id — кто пометил вручную (null = авто-пометка);
 *   - manual — null (авто) | true (вкл вручную) | false (снято вручную —
 *     подавляет авто-пометку, чтобы поток не воскрешал снятый статус).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_emails', function (Blueprint $table) {
            if (! Schema::hasColumn('dealer_emails', 'marked_by_user_id')) {
                $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('dealer_emails', 'manual')) {
                $table->boolean('manual')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dealer_emails', function (Blueprint $table) {
            if (Schema::hasColumn('dealer_emails', 'marked_by_user_id')) {
                $table->dropConstrainedForeignId('marked_by_user_id');
            }
            if (Schema::hasColumn('dealer_emails', 'manual')) {
                $table->dropColumn('manual');
            }
        });
    }
};
