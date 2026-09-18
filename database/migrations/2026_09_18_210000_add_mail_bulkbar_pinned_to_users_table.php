<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Личная настройка почтового клиента: держать панель массовых действий
 * закреплённой. По умолчанию она появляется только при выделении писем;
 * закреплённая видна всегда и просто неактивна, пока ничего не выбрано.
 *
 * Хранится у пользователя, как и порядок писем в переписке
 * (users.thread_sort_order) — настройка ходит за человеком по устройствам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'mail_bulkbar_pinned')) {
                $table->boolean('mail_bulkbar_pinned')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'mail_bulkbar_pinned')) {
                $table->dropColumn('mail_bulkbar_pinned');
            }
        });
    }
};
