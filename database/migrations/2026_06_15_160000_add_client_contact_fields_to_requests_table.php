<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контактные поля клиента из заявок с сайта (order@myzip.ru шлёт на info@
 * структурированное письмо: Организация / Адрес / Контактное лицо / Телефон /
 * E-mail). Раньше всё это терялось — Request.client_email писался техническим
 * order@myzip.ru. Теперь WebFormSubmissionParser извлекает реальные данные;
 * эти колонки хранят телефон/организацию/адрес для карточки заявки.
 *
 * client_email/client_name уже существуют — их парсер тоже переопределяет
 * реальными значениями (E-mail + Контактное лицо).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'client_phone')) {
                $table->string('client_phone')->nullable()->after('client_name');
            }
            if (! Schema::hasColumn('requests', 'client_company')) {
                $table->string('client_company')->nullable()->after('client_phone');
            }
            if (! Schema::hasColumn('requests', 'client_address')) {
                $table->string('client_address')->nullable()->after('client_company');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            foreach (['client_phone', 'client_company', 'client_address'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
