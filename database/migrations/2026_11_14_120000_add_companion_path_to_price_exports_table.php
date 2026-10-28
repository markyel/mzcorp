<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Сопроводительный файл к выгрузке по списку «Актуализации цен»: что покупаем здесь и у кого.
        Schema::table('price_exports', function (Blueprint $table) {
            if (! Schema::hasColumn('price_exports', 'companion_path')) {
                $table->string('companion_path', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('price_exports', function (Blueprint $table) {
            if (Schema::hasColumn('price_exports', 'companion_path')) {
                $table->dropColumn('companion_path');
            }
        });
    }
};
