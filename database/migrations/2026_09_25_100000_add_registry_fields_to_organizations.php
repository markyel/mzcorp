<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Официальные реквизиты организации из ЕГРЮЛ/ЕГРИП.
 *
 * Название и адрес в реестре собраны разборщиком из наших же PDF: где-то
 * обрезаны, где-то склеены с артикулом («ип DCSS5-E» при живом ИНН ООО
 * «ГРИНЛИФТ»). ИНН при этом почти везде настоящий — по нему и сверяемся.
 *
 * Официальные данные храним рядом с рабочими, а не поверх: рабочее название
 * менеджеры правят руками, и молча затирать его выпиской нельзя. Заменяем
 * только мусор; остальное — по кнопке, видя разницу.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'ogrn')) {
                $table->string('ogrn', 15)->nullable()->index();
            }
            if (! Schema::hasColumn('organizations', 'registry_short_name')) {
                $table->string('registry_short_name', 255)->nullable();
            }
            if (! Schema::hasColumn('organizations', 'registry_full_name')) {
                $table->string('registry_full_name', 500)->nullable();
            }
            if (! Schema::hasColumn('organizations', 'registry_address')) {
                $table->string('registry_address', 500)->nullable();
            }
            if (! Schema::hasColumn('organizations', 'registry_director')) {
                $table->string('registry_director', 255)->nullable();
            }
            // ACTIVE | LIQUIDATING | LIQUIDATED | BANKRUPT | REORGANIZING | NOT_FOUND
            if (! Schema::hasColumn('organizations', 'registry_status')) {
                $table->string('registry_status', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('organizations', 'registry_checked_at')) {
                $table->timestamp('registry_checked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            foreach (['ogrn', 'registry_short_name', 'registry_full_name', 'registry_address',
                'registry_director', 'registry_status', 'registry_checked_at'] as $col) {
                if (Schema::hasColumn('organizations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
