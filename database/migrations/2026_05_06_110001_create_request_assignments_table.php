<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит назначений заявок (Foundation §3 + §«Новые модели»).
 *
 * Каждое назначение — отдельная запись. История нужна для:
 * - дашборда РОПа (кто кому когда передал заявку);
 * - расчёта load-balancing метрик (cap «по N заявок в день»).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('request_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()
                ->comment('Кому назначено');
            $table->foreignId('by_user_id')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('Кто назначил (NULL для авто)');
            $table->string('reason', 64)
                ->comment('auto_round_robin | manual | reassign | manager_unavailable');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_assignments');
    }
};
