<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контактное лицо поставщика: в обращении письма RFQ/напоминания
 * подставляется имя человека, а не название компании
 * («Здравствуйте, Иван!» вместо «Здравствуйте, ООО Вертикаль!»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'contact_person')) {
                $table->string('contact_person')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'contact_person')) {
                $table->dropColumn('contact_person');
            }
        });
    }
};
