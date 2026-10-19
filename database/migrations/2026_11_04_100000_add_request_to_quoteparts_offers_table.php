<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ответ на запрос QuoteParts: какой запрос и по какой заявке — вкладка «Актуализация цен» группирует по заявкам.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'rfq_remote_id')) {
                $table->unsignedBigInteger('rfq_remote_id')->nullable()->index();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'rfq_tag')) {
                $table->string('rfq_tag', 20)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'request_id')) {
                $table->unsignedBigInteger('request_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            foreach (['rfq_remote_id', 'rfq_tag', 'request_id'] as $column) {
                if (Schema::hasColumn('quoteparts_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
