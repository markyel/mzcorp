<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §4.1: аудит всех изменений KB.
 *
 * Заполняется автоматически из observers моделей KB (в документе 4 вместе с UI).
 * На этом этапе — только таблица.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->string('action');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->default('curator');
            $table->string('source')->nullable();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_audit_log');
    }
};
