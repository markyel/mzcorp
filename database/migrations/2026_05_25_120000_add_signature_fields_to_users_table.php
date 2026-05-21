<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email signature v2 (2026-05-21):
 * добавляем поля для шаблонизированной подписи во ВСЕ outbound-письма
 * менеджера. Общая часть (юр.лицо, телефоны компании, ЭДО, info@) лежит
 * в config/services.php['company']. Персональная часть — поля User'а:
 *
 *   · name      — «Илья Курзаев»          (есть)
 *   · name_en   — «Ilya Kurzaev»          (новое)
 *   · email     — «ilya.kurzaev@myzip.ru» (есть)
 *   · phone           — основной офисный   (есть, 2026_05_23_120200)
 *   · phone_extension — доб. номер         (есть, 2026_05_23_120200)
 *   · mobile_phone    — моб/Telegram       (новое)
 *
 * Старое поле email_signature (TEXT) остаётся как опциональный
 * override: если менеджер заполнил вручную — оно используется как
 * раньше. Если пусто — рендерится шаблонная подпись через
 * EmailSignatureService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'name_en')) {
                $table->string('name_en', 128)->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'mobile_phone')) {
                $table->string('mobile_phone', 32)->nullable()->after('phone_extension');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'mobile_phone')) {
                $table->dropColumn('mobile_phone');
            }
            if (Schema::hasColumn('users', 'name_en')) {
                $table->dropColumn('name_en');
            }
        });
    }
};
