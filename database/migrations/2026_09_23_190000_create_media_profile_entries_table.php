<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Медиапрофиль компании: из чего складывается её рекламный образ.
 *
 * Одна запись — одно утверждение о себе: «фирменный цвет красный»,
 * «старейший импортёр лифтовых запчастей», «профессионально, но не сухо».
 * Такие утверждения копятся со временем и от разных людей, поэтому у записи
 * есть автор и заметка об источнике — через полгода никто не вспомнит,
 * откуда взялось «не сравниваем с конкурентами по именам».
 *
 * Дальше через этот набор будут проходить маркетинговые материалы (новости,
 * рассылки, буклеты): каждая запись — требование, которое можно проверить.
 * Поэтому текст правила отделён от пояснения: в проверку идёт `statement`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_profile_entries')) {
            return;
        }

        Schema::create('media_profile_entries', function (Blueprint $table) {
            $table->id();
            $table->string('facet', 32)->index();
            // Короткое утверждение — то, что проверяется в материалах.
            $table->string('statement', 500);
            // Развёрнутое пояснение: примеры, оговорки, откуда взялось.
            $table->text('details')->nullable();
            // Насколько жёстко: обязательное требование или пожелание.
            $table->boolean('is_strict')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_profile_entries');
    }
};
