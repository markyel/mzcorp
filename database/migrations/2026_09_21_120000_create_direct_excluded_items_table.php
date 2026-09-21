<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции, исключённые из рекламы в Директе вручную.
 *
 * Отдельная таблица, а не флаг в каталоге: каталог приходит из корпоративной
 * базы и для нас read-only, а это наше маркетинговое решение. Смысл — отсеять
 * то, что по складу и цене подходит, но само по себе спросом не пользуется:
 * расходники и комплектующие к другому товару (барабан для намотки канатов),
 * крепёж, упаковка.
 *
 * Исключение действует и на очередь раздела «Директ», и на YML-фид.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_excluded_items')) {
            return;
        }

        Schema::create('direct_excluded_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
            // Дублируем sku: каталог пересобирается импортом, а причина
            // исключения должна читаться и по артикулу, без джойна.
            $t->string('sku', 32)->index();
            $t->string('reason', 200)->nullable();
            $t->foreignId('excluded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_excluded_items');
    }
};
