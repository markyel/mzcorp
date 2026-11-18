<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Частичное КП»: менеджер нажал «ответим позже» по позиции без КП — в карточке она свёрнута до появления цены.
 * Позиция остаётся непокрытой (заявка — в «Частичном КП»), это только тише в карточке.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('request_items', 'quote_deferred_at')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->timestamp('quote_deferred_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request_items', 'quote_deferred_at')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->dropColumn('quote_deferred_at');
            });
        }
    }
};
