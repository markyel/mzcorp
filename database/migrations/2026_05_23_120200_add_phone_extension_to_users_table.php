<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контактные данные ответственного для шапки КП:
 *   «Агрызков Сергей, тел.:+7 (495) 565-37-72, доб. 203, email:sergey.agryzkov@myzip.ru»
 *
 * Используется QuotationPdfService при рендере «Ответственный:» строки
 * + при формировании subject/body email при отправке.
 *
 * Менеджер заполняет в /profile.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'phone_extension')) {
                $table->string('phone_extension', 16)->nullable()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'phone_extension')) {
                $table->dropColumn('phone_extension');
            }
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
