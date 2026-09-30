<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Организация поставщика: несколько записей реестра (адресов) одной компании —
 * sorokinra2@ и shubiniv3@kmz.mos.ru это один «КМЗ РУ». Запись реестра
 * остаётся единицей рассылки (письмо идёт на адрес), организация — группировка
 * в списках и общее название.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_organizations')) {
            Schema::create('supplier_organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->text('notes')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('name');
            });
        }

        if (! Schema::hasColumn('suppliers', 'supplier_organization_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->foreignId('supplier_organization_id')->nullable()
                    ->constrained('supplier_organizations')->nullOnDelete();
                $table->index('supplier_organization_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'supplier_organization_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('supplier_organization_id');
            });
        }
        Schema::dropIfExists('supplier_organizations');
    }
};
