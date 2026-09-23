<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Памятка по фирменному стилю, собранная из медиапрофиля.
 *
 * Профиль — это список утверждений, удобный для проверки материалов машиной,
 * но неудобный для человека: копирайтеру или подрядчику нужен связный текст по
 * жанрам. Памятку собираем из профиля и храним версиями — её отдают наружу, и
 * потом надо знать, какую именно редакцию человек получил.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_profile_guides')) {
            return;
        }

        Schema::create('media_profile_guides', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->text('profile_snapshot')->nullable();
            $table->unsignedInteger('entries_count')->default(0);
            $table->string('model', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_profile_guides');
    }
};
