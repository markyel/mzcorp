<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Выгрузки новых цен поставщиков в Excel (под 1С): за какой период, сколько строк, файл.
        // Следующая выгрузка по умолчанию начинается там, где закончилась предыдущая.
        if (! Schema::hasTable('price_exports')) {
            Schema::create('price_exports', function (Blueprint $table) {
                $table->id();
                $table->timestamp('from_at');
                $table->timestamp('to_at');
                $table->unsignedInteger('rows_count')->default(0);
                $table->unsignedInteger('suppliers_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->string('file_path')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_exports');
    }
};
