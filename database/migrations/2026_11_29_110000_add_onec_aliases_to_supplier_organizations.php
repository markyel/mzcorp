<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дополнительные коды 1С поставщика: дубль контрагента в 1С (тот же «Подий» — 00-003020 «Поставщик»
 * и 00-008624 «Покупатель»), вклеенный в основную карточку. Код дубля остаётся за ней: поиск по нему
 * находит карточку, а повторная привязка реестра 1С не заводит дубль заново.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_organizations') && ! Schema::hasColumn('supplier_organizations', 'onec_aliases')) {
            Schema::table('supplier_organizations', function (Blueprint $table) {
                $table->jsonb('onec_aliases')->nullable()->after('onec_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('supplier_organizations') && Schema::hasColumn('supplier_organizations', 'onec_aliases')) {
            Schema::table('supplier_organizations', function (Blueprint $table) {
                $table->dropColumn('onec_aliases');
            });
        }
    }
};
