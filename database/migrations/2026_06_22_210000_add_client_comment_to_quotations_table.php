<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotations', 'client_comment')) {
            Schema::table('quotations', function (Blueprint $table) {
                // Общий КЛИЕНТСКИЙ комментарий — печатается в PDF. Отдельно от
                // внутреннего `notes`, куда markCancelled/markRejected дописывают
                // причины «Отменено: …» (их клиенту показывать нельзя).
                $table->text('client_comment')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotations', 'client_comment')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropColumn('client_comment');
            });
        }
    }
};
