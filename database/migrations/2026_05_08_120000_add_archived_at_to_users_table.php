<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-archive для пользователей (Phase 1.13 — UI управления менеджерами).
 *
 * Не используем Laravel SoftDeletes (deleted_at + глобальный scope) — слишком
 * агрессивно: scope автоматически прячет записи во всех запросах, что ломает
 * audit-трейлы (request_assignments.user_id и т.п.). Вместо этого простой
 * timestamp + явный scope `User::active()` там, где это нужно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index()->after('remember_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'archived_at')) {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            }
        });
    }
};
