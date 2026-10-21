<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Игнорировать»: снабжение решило, что предложение не о том (поставщик предложил совсем
        // не то) — цена не идёт в выгрузку 1С, в «лучшее» и в выбор закупки.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'ignored_at')) {
                $table->timestamp('ignored_at')->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'ignored_by_user_id')) {
                $table->foreignId('ignored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'ignored_by_user_id')) {
                $table->dropConstrainedForeignId('ignored_by_user_id');
            }
            if (Schema::hasColumn('quoteparts_offers', 'ignored_at')) {
                $table->dropColumn('ignored_at');
            }
        });
    }
};
