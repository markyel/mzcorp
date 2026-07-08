<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Номер заявки/КП из 1С (правило заказчика, 2026-07-08): у каждой заявки
 * должен быть прописан номер из корп. базы — для синхронизации с 1С и
 * контроля менеджеров. Вводит менеджер один раз; менять может только
 * РОП/директор/админ. Пул помечает заявки без номера и фильтрует по наличию.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'onec_number')) {
                $table->string('onec_number', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'onec_number')) {
                $table->dropColumn('onec_number');
            }
        });
    }
};
