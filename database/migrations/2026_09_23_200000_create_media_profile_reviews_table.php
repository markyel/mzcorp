<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Проверки материалов по медиапрофилю.
 *
 * Храним и исходный текст, и разбор: маркетолог возвращается к материалу
 * через день, а по истории видно, какие замечания повторяются из раза в раз —
 * это подсказка, что профиль надо дополнить, а не текст переписать.
 *
 * Снимок профиля кладём рядом с разбором: профиль меняется, и через месяц
 * иначе не понять, по каким правилам материал проверяли.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_profile_reviews')) {
            return;
        }

        Schema::create('media_profile_reviews', function (Blueprint $table) {
            $table->id();
            // news | mailing | booklet | other
            $table->string('kind', 24)->index();
            $table->string('title')->nullable();
            $table->text('source_text');
            $table->text('rewritten_text')->nullable();
            // Замечания: грань, строгость, цитата, что не так, как поправить.
            $table->jsonb('issues')->nullable();
            $table->text('profile_snapshot')->nullable();
            $table->string('model', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_profile_reviews');
    }
};
