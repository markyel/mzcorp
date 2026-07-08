<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флажок «вернуться позже» на обращении в поддержку (просьба пользователя):
 * автор помечает тикет в «Моих обращениях», отмеченные всплывают наверх
 * списка и выделяются, пока флажок не снят.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('support_tickets', 'flagged_at')) {
                $table->timestamp('flagged_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('support_tickets', 'flagged_at')) {
                $table->dropColumn('flagged_at');
            }
        });
    }
};
