<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Выбор участника: одна строка на выбранное фото с местом 1..picks (1-е место — picks баллов, последнее — 1).
        // Пересохранение выбора заменяет строки целиком.
        if (Schema::hasTable('calendar_poll_votes')) {
            return;
        }
        Schema::create('calendar_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_poll_voter_id')->constrained('calendar_poll_voters')->cascadeOnDelete();
            $table->foreignId('calendar_poll_photo_id')->constrained('calendar_poll_photos')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->timestamps();

            $table->unique(['calendar_poll_voter_id', 'calendar_poll_photo_id']);
            $table->unique(['calendar_poll_voter_id', 'rank']);
            $table->index('calendar_poll_photo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_poll_votes');
    }
};
