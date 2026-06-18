<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 3.1 — профиль поставщика для подбора под позицию.
 *
 * assortment_description — ручное текстовое описание ассортимента/марок
 * («Возим запчасти KONE, OTIS — лебёдки, двери, частотники»).
 * assortment_matrix — производное, строит AI (SupplierMatrixBuilder): какие
 * бренды/категории покрывает поставщик ({brands:[], categories:[], pairs:[]}).
 * При dispatch (Фаза 3.2) — пересечение матрицы с (бренд × категория) позиции.
 * См. Foundation §4.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'phone')) {
                $table->string('phone')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'assortment_description')) {
                $table->text('assortment_description')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'assortment_matrix')) {
                $table->jsonb('assortment_matrix')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'matrix_built_at')) {
                $table->timestamp('matrix_built_at')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'matrix_built_with_model')) {
                $table->string('matrix_built_with_model')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['phone', 'assortment_description', 'assortment_matrix', 'matrix_built_at', 'matrix_built_with_model'] as $col) {
                if (Schema::hasColumn('suppliers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
