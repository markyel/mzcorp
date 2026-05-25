<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support-тикеты — «связь с создателем».
 *
 * Любой авторизованный пользователь может открыть тикет из шапки.
 * Админ (markyel) видит инбокс, отвечает, закрывает.
 *
 * context jsonb фиксирует, где именно пользователь был в момент
 * создания тикета: url, route_name, viewport, user_agent + snapshot
 * ролей. Помогает воспроизвести проблему без переписки.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 32)->default('open')
                ->comment('open | in_progress | resolved | closed');
            $table->jsonb('context')->nullable()
                ->comment('url, route_name, viewport, user_agent, roles_snapshot');
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
