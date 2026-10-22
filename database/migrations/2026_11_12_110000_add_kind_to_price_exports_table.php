<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // period — выгрузка за период («новое с конца прошлой»); positions — по списку позиций
        // «Актуализации цен». «Новое с конца прошлой» считается только по выгрузкам за период.
        Schema::table('price_exports', function (Blueprint $table) {
            if (! Schema::hasColumn('price_exports', 'kind')) {
                $table->string('kind', 16)->default('period')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('price_exports', function (Blueprint $table) {
            if (Schema::hasColumn('price_exports', 'kind')) {
                $table->dropColumn('kind');
            }
        });
    }
};
