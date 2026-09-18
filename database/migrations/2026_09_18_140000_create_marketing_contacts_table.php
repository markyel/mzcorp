<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Записная книжка раздела «Маркетинг»: подрядчики и площадки по направлениям
 * (Календари, Выставки, Сувенирка…). Одна строка — один контрагент: организация,
 * адреса, кто на связи, где лежат файлы (UNC-путь) и заметка.
 *
 * Отдельно от marketing_services: там доступы (логин/пароль к кабинету), здесь
 * люди и организации, с которыми работаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_contacts')) {
            return;
        }

        Schema::create('marketing_contacts', function (Blueprint $t) {
            $t->id();
            // Направление: «Календари», «Выставка», «Сувенирная продукция»…
            // Свободный текст: список направлений живой, жёсткий справочник мешал бы.
            $t->string('topic', 80)->index();
            $t->string('organization', 200);
            $t->string('contact_person', 160)->nullable();
            // Адресов бывает несколько (общий ящик + менеджер).
            $t->jsonb('emails')->nullable();
            $t->string('phone', 120)->nullable();
            // Локальная папка с файлами, обычно UNC: \\phobos\MyZip\...
            $t->string('folder_path', 500)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_contacts');
    }
};
