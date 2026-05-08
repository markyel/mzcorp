<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.6: атомарные параметры идентификации.
 *
 * Один параметр = одна сущность (цвет подсветки, диаметр, марка лифта).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identification_parameters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug')->unique();
            $table->string('name');

            $table->enum('value_type', ['text', 'number', 'photo', 'select', 'multi_select']);
            $table->jsonb('allowed_values')->default('[]');
            $table->string('unit')->nullable();
            $table->text('question_template');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identification_parameters');
    }
};
