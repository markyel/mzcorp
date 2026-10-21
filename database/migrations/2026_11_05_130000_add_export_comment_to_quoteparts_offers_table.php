<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Комментарий к предложению в выгрузке 1С, поправленный снабжением. null — собирается сам
        // (PriceExportService::offerComment); пустая строка — комментария нет.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'export_comment')) {
                $table->text('export_comment')->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'export_comment_by_user_id')) {
                $table->foreignId('export_comment_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'export_comment_by_user_id')) {
                $table->dropConstrainedForeignId('export_comment_by_user_id');
            }
            if (Schema::hasColumn('quoteparts_offers', 'export_comment')) {
                $table->dropColumn('export_comment');
            }
        });
    }
};
