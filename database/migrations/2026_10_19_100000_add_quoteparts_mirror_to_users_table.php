<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'quoteparts_mirror')) {
            Schema::table('users', function (Blueprint $table) {
                // Дублирование переписки QuoteParts на почту автора запроса: последний выбор
                // пользователя в форме запроса, чтобы не отмечать галочку каждый раз.
                $table->boolean('quoteparts_mirror')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'quoteparts_mirror')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('quoteparts_mirror'));
        }
    }
};
