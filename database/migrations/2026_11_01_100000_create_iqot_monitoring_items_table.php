<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Позиции каталога на мониторинге цен IQOT (программа в режиме hybrid): что отправили,
        // как IQOT их сопоставил и какие цены поставщиков нашёл (сайты и КП).
        if (Schema::hasTable('iqot_monitoring_items')) {
            return;
        }
        Schema::create('iqot_monitoring_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
            // Ключ позиции в IQOT — наш M-артикул (внутренний код идёт в client_ref, не в article).
            $table->string('client_ref', 191)->unique();
            // refresh — из «К актуализации»; manual — добавлена вручную из каталога.
            $table->string('source', 16)->default('refresh');
            $table->boolean('active')->default(true)->index();

            // Что отправлено (снимок) и когда.
            $table->jsonb('payload')->nullable();
            $table->string('payload_hash', 40)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('import_id', 64)->nullable()->index();
            $table->string('import_status', 16)->nullable();
            $table->string('import_error')->nullable();

            // Сопоставление с товаром в IQOT.
            $table->string('match_status', 16)->nullable()->index();
            $table->text('match_note')->nullable();

            // Цены (GET …/prices).
            $table->timestamp('last_priced_at')->nullable();
            $table->timestamp('prices_changed_at')->nullable();
            $table->decimal('best_price', 14, 2)->nullable();
            $table->string('best_source', 8)->nullable();
            $table->string('best_supplier_domain')->nullable();
            $table->text('best_url')->nullable();
            $table->decimal('price_min', 14, 2)->nullable();
            $table->decimal('price_median', 14, 2)->nullable();
            $table->decimal('price_max', 14, 2)->nullable();
            $table->unsignedInteger('sources_priced')->default(0);
            $table->boolean('has_kp')->default(false);
            $table->jsonb('sources')->nullable();
            $table->timestamp('prices_synced_at')->nullable();

            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iqot_monitoring_items');
    }
};
