<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автоматический мониторинг цен (Фаза 4.3). Одна строка — одна каталожная
 * позиция, по которой снабженец включил регулярный перезапрос цены у
 * поставщиков. Набор поставщиков запоминается с той отправки, на которой
 * мониторинг включили: повторный запрос уходит тем же адресатам.
 *
 * Мониторинг НЕ снимается сам, когда цена снова стала актуальной — через
 * interval_days она снова устареет, в этом и смысл. Выключение ручное
 * (is_active = false), см. PriceMonitorService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_monitors')) {
            return;
        }

        Schema::create('price_monitors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();

            $t->unsignedSmallInteger('interval_days')->default(90)
                ->comment('Период перезапроса цены, дней. По умолчанию 90.');
            $t->jsonb('supplier_ids')->nullable()
                ->comment('id поставщиков, которым уходит повторный запрос');

            $t->timestamp('last_dispatched_at')->nullable()
                ->comment('Когда последний раз ушёл запрос по этой позиции');
            $t->timestamp('next_due_at')->nullable()
                ->comment('Когда уйдёт следующий; null у выключенных');
            $t->unsignedInteger('dispatch_count')->default(0);

            $t->boolean('is_active')->default(true);
            $t->timestamp('disabled_at')->nullable();

            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('last_inquiry_id')->nullable()->constrained('supplier_inquiries')->nullOnDelete();
            $t->timestamps();

            // Основная выборка планировщика: активные, у которых подошёл срок.
            $t->index(['is_active', 'next_due_at'], 'price_monitors_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_monitors');
    }
};
