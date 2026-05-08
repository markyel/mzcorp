<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.8: альтернативные наборы параметров внутри правила.
 *
 * Хоть один альтернативный набор полностью покрыт = позиция считается достаточной.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identification_rule_alternatives', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('rule_id')
                ->constrained('identification_rules')
                ->cascadeOnDelete();

            $table->jsonb('required_parameter_ids');
            $table->string('label')->nullable();
            $table->integer('preference_order')->default(100);
            $table->timestamps();

            $table->index('rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identification_rule_alternatives');
    }
};
