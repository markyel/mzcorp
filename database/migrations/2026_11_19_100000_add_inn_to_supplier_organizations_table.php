<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ИНН у организации поставщика: клиент, переведённый в поставщики, уносит ИНН с собой,
 * и импорт клиентской базы из 1С больше не заводит его клиентом.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_organizations') && ! Schema::hasColumn('supplier_organizations', 'inn')) {
            Schema::table('supplier_organizations', function (Blueprint $table) {
                $table->string('inn', 20)->nullable()->index()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('supplier_organizations') && Schema::hasColumn('supplier_organizations', 'inn')) {
            Schema::table('supplier_organizations', function (Blueprint $table) {
                $table->dropIndex(['inn']);
                $table->dropColumn('inn');
            });
        }
    }
};
