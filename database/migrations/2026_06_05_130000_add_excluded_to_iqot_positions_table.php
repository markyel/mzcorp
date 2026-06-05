<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исключение позиции из пула IQOT навсегда («не запрашивать никогда»).
 * excluded_at != null → позицию не собираем в пул, не отправляем, отчёт не
 * перетираем. Снимается вручную («Вернуть») или явным ручным запросом из каталога.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (! Schema::hasColumn('iqot_positions', 'excluded_at')) {
                $t->timestamp('excluded_at')->nullable()->index();
            }
            if (! Schema::hasColumn('iqot_positions', 'excluded_by_user_id')) {
                $t->foreignId('excluded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (Schema::hasColumn('iqot_positions', 'excluded_by_user_id')) {
                $t->dropConstrainedForeignId('excluded_by_user_id');
            }
            if (Schema::hasColumn('iqot_positions', 'excluded_at')) {
                $t->dropColumn('excluded_at');
            }
        });
    }
};
