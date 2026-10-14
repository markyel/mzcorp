<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_price_imports')) {
            return;
        }

        // Загрузки выгрузки 1С «Номенклатура (история цен)» из интерфейса: файл, сводка
        // пробного разбора, подтверждение записи. Сами цены — в catalog_supplier_prices.
        Schema::create('supplier_price_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            // uploaded → parsing → parsed → applying → applied; failed — ошибка на любом шаге.
            $table->string('status', 20)->default('uploaded')->index();
            $table->jsonb('stats')->nullable();
            $table->jsonb('unmatched_suppliers')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('written')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_imports');
    }
};
