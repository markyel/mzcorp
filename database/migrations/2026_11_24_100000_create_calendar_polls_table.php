<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Опрос «Фото для календаря»: сотрудники выбирают по picks фото, по рейтингу берём winners.
        // Статус draft → open → closed; закрытый опрос голосов не принимает.
        if (Schema::hasTable('calendar_polls')) {
            return;
        }
        Schema::create('calendar_polls', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('picks');
            $table->unsignedSmallInteger('winners');
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_polls');
    }
};
