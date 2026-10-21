<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Справочник контрагентов 1С УНФ (выгрузка «Список контрагентов»): код поставщика для
        // выгрузки цен в 1С. Обновляется загрузкой файла; строки, которых нет в новом файле, остаются.
        if (! Schema::hasTable('onec_counterparties')) {
            Schema::create('onec_counterparties', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->unique();
                $table->string('name', 500);
                $table->string('name_normalized', 500)->index();
                $table->string('full_name', 500)->nullable();
                $table->string('inn', 20)->nullable()->index();
                $table->string('contract', 255)->nullable();
                $table->timestamp('seen_at')->nullable();
                $table->timestamps();
            });
        }

        // Привязка нашего поставщика (ключ org:{id} / sup:{id}) к контрагенту 1С, поставленная
        // человеком: когда название не совпадает однозначно («СИНТЭК» — два контрагента).
        if (! Schema::hasTable('supplier_onec_codes')) {
            Schema::create('supplier_onec_codes', function (Blueprint $table) {
                $table->id();
                $table->string('supplier_key', 64)->unique();
                $table->string('code', 32);
                $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_onec_codes');
        Schema::dropIfExists('onec_counterparties');
    }
};
