<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Приглашение поставщика в QuoteParts: id связи там, её статус и комментарий поставщика
            // (если он предложил другие условия).
            if (! Schema::hasColumn('suppliers', 'quoteparts_link_id')) {
                $table->unsignedBigInteger('quoteparts_link_id')->nullable()->index();
            }
            if (! Schema::hasColumn('suppliers', 'quoteparts_status')) {
                $table->string('quoteparts_status', 20)->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'quoteparts_invited_at')) {
                $table->timestamp('quoteparts_invited_at')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'quoteparts_comment')) {
                $table->text('quoteparts_comment')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['quoteparts_link_id', 'quoteparts_status', 'quoteparts_invited_at', 'quoteparts_comment'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
