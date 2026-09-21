<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Скидки контрагентов из корпоративной базы.
 *
 * Держим их отдельной таблицей, а не только в карточке организации, по двум
 * причинам. Во-первых, список приходит целиком выгрузкой и содержит
 * контрагентов, которых у нас ещё нет: когда организация появится, скидка
 * применится сама. Во-вторых, так видно источник и дату — «почему у клиента
 * 20%» отвечается ссылкой на файл, а не памятью.
 *
 * Ключ — ИНН: имена в выгрузке живые («АКМЕ ООО (МСК) ЭДО»), сопоставлять по
 * ним нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_discounts')) {
            return;
        }

        Schema::create('client_discounts', function (Blueprint $t) {
            $t->id();
            $t->string('inn', 12)->unique();
            $t->string('name', 255);
            $t->string('group_name', 255)->nullable();
            $t->decimal('discount_percent', 5, 2)->default(0);
            // С какой организацией сопоставилось на момент импорта.
            $t->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->string('source_file', 255)->nullable();
            $t->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('group_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_discounts');
    }
};
