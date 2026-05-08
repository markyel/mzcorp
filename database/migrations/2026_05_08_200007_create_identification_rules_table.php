<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.7: правила идентификации.
 *
 * Одно правило = "для категории X (опционально + список брендов)
 * применяется набор альтернативных требований Y".
 *
 * applies_to_brands jsonb: null = для всех брендов категории; [1,5,12] = только для указанных.
 * priority: меньший побеждает (маркоспецифичные обычно 50, общие 100).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identification_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('equipment_categories')
                ->nullOnDelete();

            $table->jsonb('applies_to_brands')->nullable();
            $table->text('description')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identification_rules');
    }
};
