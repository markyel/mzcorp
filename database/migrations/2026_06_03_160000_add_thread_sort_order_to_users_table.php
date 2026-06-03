<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персональная настройка порядка писем в табе «Переписка» карточки заявки.
 * 'asc' — сначала старые (как было), 'desc' — сначала новые (как в почтовых
 * клиентах). Сохраняется per-user, применяется ко всем заявкам.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'thread_sort_order')) {
                $table->string('thread_sort_order', 8)->default('asc');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'thread_sort_order')) {
                $table->dropColumn('thread_sort_order');
            }
        });
    }
};
