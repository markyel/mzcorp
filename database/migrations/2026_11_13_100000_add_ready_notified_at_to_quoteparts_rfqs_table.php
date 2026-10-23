<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Когда автору запроса ушло письмо «цены собраны — можно выбирать» (один раз на запрос).
        Schema::table('quoteparts_rfqs', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_rfqs', 'ready_notified_at')) {
                $table->timestamp('ready_notified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_rfqs', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_rfqs', 'ready_notified_at')) {
                $table->dropColumn('ready_notified_at');
            }
        });
    }
};
