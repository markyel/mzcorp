<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Участники опроса — по списку «имя + e-mail», в системе они или нет (user_id, если адрес совпал с сотрудником).
        // Ссылка входа одноразовая: в базе только sha256 токена; повторная выдача заменяет прежнюю.
        if (Schema::hasTable('calendar_poll_voters')) {
            return;
        }
        Schema::create('calendar_poll_voters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_poll_id')->constrained('calendar_polls')->cascadeOnDelete();
            $table->string('name');
            $table->string('email', 190);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_used_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();

            $table->unique(['calendar_poll_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_poll_voters');
    }
};
