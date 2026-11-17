<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Фото опроса: оригинал как загрузили + копии для галереи (миниатюра и просмотр). Файлы — на приватном диске.
        // Копии делает фоновая задача (большие JPEG, десятки штук) — до неё пути копий пустые.
        if (Schema::hasTable('calendar_poll_photos')) {
            return;
        }
        Schema::create('calendar_poll_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_poll_id')->constrained('calendar_polls')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('preview_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['calendar_poll_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_poll_photos');
    }
};
